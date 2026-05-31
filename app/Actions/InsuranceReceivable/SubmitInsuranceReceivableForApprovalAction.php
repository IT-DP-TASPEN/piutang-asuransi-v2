<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitInsuranceReceivableForApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
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

        if ($insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED) {
            throw ValidationException::withMessages([
                'system_status' => 'Loan inquiry must complete successfully before submission.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $approvalRequest = $this->approvalService->submit(
                approvable: $insuranceReceivable,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
                actor: $user,
                notes: $notes,
            );

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'submitted_for_branch_approval',
                fromStatus: InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                description: 'Submitted for branch approval.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
