<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Validation\ValidationException;

class ApproveClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ClaimStatusChangeRequest $claimStatusChangeRequest, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        if ($claimStatusChangeRequest->insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'insurance_receivable_id' => 'Terminal receivables cannot update claim status.',
            ]);
        }

        $this->approvalService->approveCurrentStep($this->activeApprovalRequestFor($claimStatusChangeRequest), $user, $notes);

        return $claimStatusChangeRequest->refresh();
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
