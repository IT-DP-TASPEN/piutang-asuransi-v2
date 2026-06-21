<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutoSubmitInsuranceReceivableForBranchApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user): InsuranceReceivable {
            $insuranceReceivable = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($insuranceReceivable->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Terminal receivables cannot be submitted for branch approval.',
                ]);
            }

            if (! in_array($insuranceReceivable->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
                InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Only draft or branch-returned receivables can be auto-submitted for branch approval.',
                ]);
            }

            if ($insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED) {
                throw ValidationException::withMessages([
                    'system_status' => 'Loan inquiry must complete before branch approval auto-submit.',
                ]);
            }

            if (! $insuranceReceivable->hasCompleteRequiredDocuments()) {
                throw ValidationException::withMessages([
                    'documents' => 'Required documents must be complete before branch approval auto-submit.',
                ]);
            }

            $activeBranchApproval = $this->approvalService->latestActiveRequest(
                $insuranceReceivable,
                ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
            );

            if ($activeBranchApproval instanceof ApprovalRequest) {
                return $insuranceReceivable->refresh();
            }

            $fromWorkflowStatus = $insuranceReceivable->workflow_status;

            $approvalRequest = $this->approvalService->submit(
                approvable: $insuranceReceivable,
                workflowCode: ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
                actor: $user,
                notes: 'Submitted for BM approval.',
            );

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'auto_submitted_for_branch_approval',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                description: 'Submitted for BM approval.',
                actor: $user,
                approvalRequest: $approvalRequest,
                triggeredByType: 'system',
            );

            return $insuranceReceivable->refresh();
        });
    }
}
