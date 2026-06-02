<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveEarlyTerminationManuallyAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $insuranceReceivable = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $insuranceReceivable->canResolveEarlyTermination()) {
                throw ValidationException::withMessages([
                    'system_status' => 'Only failed early termination can be manually resolved.',
                ]);
            }

            $fromWorkflowStatus = $insuranceReceivable->workflow_status;
            $fromSystemStatus = $insuranceReceivable->system_status;

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                'last_error_message' => null,
                'early_termination_resolved_at' => now(),
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'early_termination_resolved_manually',
                fromStatus: $fromSystemStatus,
                toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                description: $notes ?: 'Early termination failure resolved manually in core banking.',
                metadata: [
                    'from_workflow_status' => $fromWorkflowStatus,
                    'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                ],
                actor: $user,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
