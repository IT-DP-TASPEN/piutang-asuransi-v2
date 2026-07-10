<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(ClaimStatusChangeRequest $request, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        if ($request->insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'insurance_receivable_id' => 'Terminal receivables cannot update claim status.',
            ]);
        }

        if (! in_array($request->status, [
            ClaimStatusChangeRequest::STATUS_DRAFT,
            ClaimStatusChangeRequest::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or returned claim status requests can be submitted.',
            ]);
        }

        if ($request->insuranceReceivable->claimStatusChangeRequests()
            ->whereKeyNot($request->id)
            ->whereIn('status', [
                ClaimStatusChangeRequest::STATUS_DRAFT,
                ClaimStatusChangeRequest::STATUS_SUBMITTED,
                ClaimStatusChangeRequest::STATUS_RETURNED,
            ])
            ->exists()) {
            throw ValidationException::withMessages([
                'claim_status' => 'A pending claim status update already exists for this receivable.',
            ]);
        }

        $this->assertDecisionTarget($request->to_claim_status_id);

        return DB::transaction(function () use ($request, $user, $notes): ClaimStatusChangeRequest {
            $request->forceFill([
                'from_claim_status_id' => $request->insuranceReceivable->claim_status_id,
                'requested_by' => $request->requested_by ?? $user->id,
                'status' => ClaimStatusChangeRequest::STATUS_SUBMITTED,
            ])->save();

            $approvalRequest = $this->approvalService->submit(
                approvable: $request,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE,
                actor: $user,
                notes: $notes,
            );

            $this->stageLogger->log(
                receivable: $request->insuranceReceivable,
                event: 'claim_status_update_requested',
                fromStatus: $request->fromClaimStatus?->code,
                toStatus: $request->toClaimStatus?->code,
                description: $notes ?: ($request->reason ?: 'Claim status update requested.'),
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $request->refresh();
        });
    }

    private function assertDecisionTarget(mixed $targetStatusId): void
    {
        if (! ClaimStatus::query()
            ->whereKey($targetStatusId)
            ->where('is_active', true)
            ->whereIn('code', ClaimStatus::DECISION_CODES)
            ->exists()) {
            throw ValidationException::withMessages([
                'to_claim_status_id' => 'Target claim status must be an active claim decision status.',
            ]);
        }
    }
}
