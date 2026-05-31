<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ClaimStatusChangeRequest $request, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        if (! in_array($request->status, [
            ClaimStatusChangeRequest::STATUS_DRAFT,
            ClaimStatusChangeRequest::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or returned claim status requests can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($request, $user, $notes): ClaimStatusChangeRequest {
            $request->forceFill([
                'from_claim_status_id' => $request->insuranceReceivable->claim_status_id,
                'requested_by' => $request->requested_by ?? $user->id,
                'status' => ClaimStatusChangeRequest::STATUS_SUBMITTED,
            ])->save();

            $this->approvalService->submit(
                approvable: $request,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE,
                actor: $user,
                notes: $notes,
            );

            return $request->refresh();
        });
    }
}
