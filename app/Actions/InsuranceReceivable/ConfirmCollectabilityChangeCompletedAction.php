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
            $fromStatus = $insuranceReceivable->workflow_status;

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'collectability_change_confirmed',
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
                description: 'IT confirmed collectability change was completed in core banking.',
                actor: $user,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
