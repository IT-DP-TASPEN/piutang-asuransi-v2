<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitInsuranceReceivableForApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        if (! in_array($insuranceReceivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Only draft or returned receivables can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $this->approvalService->submit(
                approvable: $insuranceReceivable,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
                actor: $user,
                notes: $notes,
            );

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            return $insuranceReceivable->refresh();
        });
    }
}
