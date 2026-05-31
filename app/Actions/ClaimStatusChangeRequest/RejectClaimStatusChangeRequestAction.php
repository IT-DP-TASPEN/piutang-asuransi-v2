<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(ClaimStatusChangeRequest $claimStatusChangeRequest, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        return DB::transaction(function () use ($claimStatusChangeRequest, $user, $notes): ClaimStatusChangeRequest {
            $approvalRequest = $this->activeApprovalRequestFor($claimStatusChangeRequest);
            $this->approvalService->rejectCurrentStep($approvalRequest, $user, $notes);

            $claimStatusChangeRequest->forceFill([
                'status' => ClaimStatusChangeRequest::STATUS_REJECTED,
            ])->save();

            $this->stageLogger->log(
                receivable: $claimStatusChangeRequest->insuranceReceivable,
                event: 'claim_status_update_rejected',
                fromStatus: $claimStatusChangeRequest->fromClaimStatus?->code,
                toStatus: $claimStatusChangeRequest->toClaimStatus?->code,
                description: $notes ?: 'Claim status update rejected.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

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
