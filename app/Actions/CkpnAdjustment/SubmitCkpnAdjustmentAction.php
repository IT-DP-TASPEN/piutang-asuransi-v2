<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCkpnAdjustmentAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnAdjustment $adjustment, User $user, ?string $notes = null): CkpnAdjustment
    {
        if (blank($adjustment->reason)) {
            throw ValidationException::withMessages([
                'reason' => 'CKPN adjustment reason is required.',
            ]);
        }

        if (! in_array($adjustment->status, [
            CkpnAdjustment::STATUS_DRAFT,
            CkpnAdjustment::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or returned CKPN adjustments can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($adjustment, $user, $notes): CkpnAdjustment {
            $this->approvalService->submit(
                approvable: $adjustment,
                workflowCode: ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT,
                actor: $user,
                notes: $notes,
            );

            $adjustment->forceFill([
                'requested_by' => $adjustment->requested_by ?? $user->id,
                'status' => CkpnAdjustment::STATUS_SUBMITTED,
            ])->save();

            return $adjustment->refresh();
        });
    }
}
