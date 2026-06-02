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
        if (! $user->can('submitForApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Manual branch approval submission is not available.',
            ]);
        }

        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be submitted.',
            ]);
        }

        if (! in_array($insuranceReceivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Only draft or branch-returned receivables can be submitted.',
            ]);
        }

        if ($insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED) {
            throw ValidationException::withMessages([
                'system_status' => 'Loan inquiry must complete successfully before submission.',
            ]);
        }

        if (! $insuranceReceivable->hasCompleteRequiredDocuments()) {
            throw ValidationException::withMessages([
                'documents' => 'Required documents must be complete before submission.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $activeRequest = $this->approvalService->latestActiveRequest(
                $insuranceReceivable,
                ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
            );

            if ($activeRequest instanceof ApprovalRequest) {
                throw ValidationException::withMessages([
                    'approval' => 'Active branch approval request already exists.',
                ]);
            }

            $fromWorkflowStatus = $insuranceReceivable->workflow_status;

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
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                description: 'Submitted for branch approval.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
