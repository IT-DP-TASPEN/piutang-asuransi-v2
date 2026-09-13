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

class AutoSubmitInsuranceReceivableForInitialApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user): InsuranceReceivable {
            $insuranceReceivable = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($insuranceReceivable->isLegacyOrigin()) {
                throw ValidationException::withMessages([
                    'origin_type' => 'Legacy receivables cannot enter formation workflow.',
                ]);
            }

            if ($insuranceReceivable->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Terminal receivables cannot be submitted for initial formation approval.',
                ]);
            }

            if (! in_array($insuranceReceivable->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Only draft or branch-returned receivables can be auto-submitted for initial formation approval.',
                ]);
            }

            if ($insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED) {
                throw ValidationException::withMessages([
                    'system_status' => 'Loan inquiry must complete before initial formation approval.',
                ]);
            }

            try {
                $expectedOper = $this->operAccount->expected($insuranceReceivable);
            } catch (ValidationException $exception) {
                return $this->returnForInvalidOper($insuranceReceivable, $user, $this->validationMessage($exception));
            }

            if (! $this->operAccount->matches($insuranceReceivable)) {
                $actualOper = $this->operAccount->actual($insuranceReceivable);

                return $this->returnForInvalidOper(
                    $insuranceReceivable,
                    $user,
                    "Repayment account must be branch OPER {$expectedOper}; actual: ".($actualOper === '' ? '(empty)' : $actualOper).'.',
                    $expectedOper,
                    $actualOper,
                );
            }

            $insuranceReceivable->forceFill([
                'saving_account_for_loan_repayment' => $expectedOper,
            ])->save();

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
                notes: 'Submitted for initial formation approval.',
            );

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'auto_submitted_for_initial_approval',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                description: 'Submitted for BM, Manager Asuransi, and Manager Bisnis approval.',
                actor: $user,
                approvalRequest: $approvalRequest,
                triggeredByType: 'system',
            );

            return $insuranceReceivable->refresh();
        });
    }

    private function returnForInvalidOper(
        InsuranceReceivable $receivable,
        User $user,
        string $message,
        ?string $expected = null,
        ?string $actual = null,
    ): InsuranceReceivable {
        $fromStatus = $receivable->workflow_status;

        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_REINQUIRY_REQUIRED,
            'last_error_message' => $message,
        ])->save();

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'oper_account_validation_failed',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            description: $message,
            metadata: [
                'expected_oper_account' => $expected,
                'actual_repayment_account' => $actual,
            ],
            actor: $user,
            triggeredByType: 'system',
        );

        return $receivable->refresh();
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
    }
}
