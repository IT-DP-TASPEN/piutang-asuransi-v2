<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCkpnAdjustmentAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnAdjustment $adjustment, User $user, ?string $notes = null): CkpnAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user, $notes): CkpnAdjustment {
            $approvalRequest = $this->activeApprovalRequestFor($adjustment);
            $this->approvalService->approveCurrentStep($approvalRequest, $user, $notes);

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
