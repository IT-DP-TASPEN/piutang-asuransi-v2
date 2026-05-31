<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ClaimStatusChangeRequest $claimStatusChangeRequest, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        return DB::transaction(function () use ($claimStatusChangeRequest, $user, $notes): ClaimStatusChangeRequest {
            $approvalRequest = $this->activeApprovalRequestFor($claimStatusChangeRequest);
            $approvalRequest = $this->approvalService->approveCurrentStep($approvalRequest, $user, $notes);

            if ($approvalRequest->status !== ApprovalRequest::STATUS_APPROVED) {
                return $claimStatusChangeRequest->refresh();
            }

            $claimStatusChangeRequest->insuranceReceivable->forceFill([
                'claim_status_id' => $claimStatusChangeRequest->to_claim_status_id,
            ])->save();

            $claimStatusChangeRequest->forceFill([
                'status' => ClaimStatusChangeRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $claimStatusChangeRequest->refresh();
        });
    }

    private function activeApprovalRequestFor(ClaimStatusChangeRequest $claimStatusChangeRequest): ApprovalRequest
    {
        $approvalRequest = $this->approvalService->latestActiveRequest(
            approvable: $claimStatusChangeRequest,
            workflowCode: ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE,
        );

        if (! $approvalRequest instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active claim status approval request not found.',
            ]);
        }

        return $approvalRequest;
    }
}
