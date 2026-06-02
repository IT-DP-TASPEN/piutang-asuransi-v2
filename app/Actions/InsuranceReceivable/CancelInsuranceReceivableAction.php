<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelInsuranceReceivableAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, string $notes): InsuranceReceivable
    {
        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Cancellation notes are required.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $insuranceReceivable = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $insuranceReceivable->canCancelFailedInquiry()) {
                throw ValidationException::withMessages([
                    'system_status' => 'Only unresolved technical inquiry failures can be cancelled.',
                ]);
            }

            $fromWorkflowStatus = $insuranceReceivable->workflow_status;

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'technical_inquiry_failure_cancelled',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
                description: "Insurance Receivable cancelled by user due to unresolved technical inquiry failure: {$notes}",
                metadata: [
                    'system_status' => $insuranceReceivable->system_status,
                ],
                actor: $user,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
