<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmCollectabilityChangeCompletedAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be forwarded.',
            ]);
        }

        if ($insuranceReceivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Receivable is not waiting for collectability confirmation.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Terminal receivables cannot be forwarded.',
                ]);
            }

            if ($locked->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Receivable is not waiting for collectability confirmation.',
                ]);
            }

            $fromWorkflowStatus = $locked->workflow_status;
            $fromSystemStatus = $locked->system_status;
            $manualRequirement = $locked->manualEarlyTerminationRequirement();

            if ($manualRequirement !== null) {
                $locked->forceFill([
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
                    'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
                    'last_error_message' => $manualRequirement['message'],
                ])->save();

                $this->stageLogger->log(
                    receivable: $locked,
                    event: 'collectability_change_confirmed',
                    fromStatus: $fromWorkflowStatus,
                    toStatus: InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
                    description: 'Collectability change confirmed.',
                    actor: $user,
                );

                $this->stageLogger->log(
                    receivable: $locked,
                    event: 'early_termination_manual_execution_required',
                    fromStatus: $fromSystemStatus,
                    toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
                    description: $manualRequirement['message'],
                    metadata: [
                        'repayment_saving_account' => $manualRequirement['repayment_account'],
                        'reason' => $manualRequirement['reason'],
                        'previous_workflow_status' => $fromWorkflowStatus,
                        'previous_system_status' => $fromSystemStatus,
                    ],
                    actor: $user,
                );

                return $locked->refresh();
            }

            $locked->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
            ])->save();

            $this->stageLogger->log(
                receivable: $locked,
                event: 'collectability_change_confirmed',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
                description: 'Collectability change confirmed.',
                actor: $user,
            );

            return $locked->refresh();
        });
    }
}
