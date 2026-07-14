<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnCkpnAdjustmentAction
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

        if (! $user->can('returnRequest', $adjustment)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can return CKPN adjustments.',
            ]);
        }

        return DB::transaction(function () use ($adjustment, $user, $notes): CkpnAdjustment {
            $approvalRequest = $this->activeApprovalRequestFor($adjustment);
            $this->approvalService->returnCurrentStep($approvalRequest, $user, $notes);

            $adjustment->forceFill([
                'status' => CkpnAdjustment::STATUS_RETURNED,
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
