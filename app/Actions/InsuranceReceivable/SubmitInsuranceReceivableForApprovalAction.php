<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitInsuranceReceivableForApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter formation workflow.',
            ]);
        }

        if (! $user->can('submitForApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Manual initial approval submission is not available.',
            ]);
        }

        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be submitted.',
            ]);
        }

        if (! in_array($insuranceReceivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
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

        $this->operAccount->assertMatches($insuranceReceivable);

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $activeRequest = $this->approvalService->latestActiveRequest(
                $insuranceReceivable,
                ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
            );

            if ($activeRequest instanceof ApprovalRequest) {
                throw ValidationException::withMessages([
                    'approval' => 'Active initial formation approval request already exists.',
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
                event: 'submitted_for_initial_approval',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                description: 'Submitted for BM, Manager Asuransi, and Manager Bisnis approval.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $insuranceReceivable->refresh();
        });
    }
}
