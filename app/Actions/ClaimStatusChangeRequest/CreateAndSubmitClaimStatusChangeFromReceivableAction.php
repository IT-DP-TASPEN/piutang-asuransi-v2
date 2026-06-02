<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAndSubmitClaimStatusChangeFromReceivableAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(InsuranceReceivable $receivable, User $user, array $data): ClaimStatusChangeRequest
    {
        if ($receivable->isTerminal()) {
            throw ValidationException::withMessages([
                'insurance_receivable_id' => 'Terminal receivables cannot update claim status.',
            ]);
        }

        if ($receivable->claimStatusChangeRequests()
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

        return DB::transaction(function () use ($receivable, $user, $data): ClaimStatusChangeRequest {
            $request = $receivable->claimStatusChangeRequests()->create([
                'from_claim_status_id' => $receivable->claim_status_id,
                'to_claim_status_id' => $data['to_claim_status_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'supporting_document_path' => $data['supporting_document_path'] ?? null,
                'requested_by' => $user->id,
                'status' => ClaimStatusChangeRequest::STATUS_SUBMITTED,
            ]);

            $approvalRequest = $this->approvalService->submit(
                approvable: $request,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE,
                actor: $user,
                notes: $data['reason'] ?? null,
            );

            $this->stageLogger->log(
                receivable: $receivable,
                event: 'claim_status_update_requested',
                fromStatus: $receivable->claimStatus?->code,
                toStatus: $request->toClaimStatus?->code,
                description: $request->reason ?: 'Claim status update requested.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $request->refresh();
        });
    }
}
