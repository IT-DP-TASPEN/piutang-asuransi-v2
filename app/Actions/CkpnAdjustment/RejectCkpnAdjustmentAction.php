<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectCkpnAdjustmentAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnAdjustment $adjustment, User $user, ?string $notes = null): CkpnAdjustment
    {
        if ($adjustment->status !== CkpnAdjustment::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'approval' => 'This approval request is no longer pending.',
            ]);
        }

        if (! $user->can('reject', $adjustment)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can reject CKPN adjustments.',
            ]);
        }

        return DB::transaction(function () use ($adjustment, $user, $notes): CkpnAdjustment {
            $approvalRequest = $this->activeApprovalRequestFor($adjustment);
            $this->approvalService->rejectCurrentStep($approvalRequest, $user, $notes);

            $adjustment->forceFill([
                'status' => CkpnAdjustment::STATUS_REJECTED,
            ])->save();

            return $adjustment->refresh();
        });
    }

    private function activeApprovalRequestFor(CkpnAdjustment $adjustment): ApprovalRequest
    {
        $approvalRequest = $this->approvalService->latestActiveRequest($adjustment, ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT);

        if (! $approvalRequest instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active CKPN adjustment approval request not found.',
            ]);
        }

        return $approvalRequest;
    }
}
