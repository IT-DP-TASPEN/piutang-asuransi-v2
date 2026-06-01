<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveFailedInsuranceReceivableAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, string $notes): InsuranceReceivable
    {
        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Manual resolution notes are required.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $insuranceReceivable = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($insuranceReceivable->system_status, [
                InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
                InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED,
            ], true)) {
                throw ValidationException::withMessages([
                    'system_status' => 'Only failed or invalid inquiry receivables can be manually resolved.',
                ]);
            }

            $fromWorkflowStatus = $insuranceReceivable->workflow_status;

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'manual_failed_inquiry_resolution',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
                description: "Manual resolution of failed/invalid inquiry before CKPN cutoff: {$notes}",
                metadata: [
                    'system_status' => $insuranceReceivable->system_status,
                ],
                actor: $user,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
