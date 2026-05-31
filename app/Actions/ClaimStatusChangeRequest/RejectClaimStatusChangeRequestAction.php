<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ClaimStatusChangeRequest $claimStatusChangeRequest, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        return DB::transaction(function () use ($claimStatusChangeRequest, $user, $notes): ClaimStatusChangeRequest {
            $approvalRequest = $this->activeApprovalRequestFor($claimStatusChangeRequest);
            $this->approvalService->rejectCurrentStep($approvalRequest, $user, $notes);

            $claimStatusChangeRequest->forceFill([
                'status' => ClaimStatusChangeRequest::STATUS_REJECTED,
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
