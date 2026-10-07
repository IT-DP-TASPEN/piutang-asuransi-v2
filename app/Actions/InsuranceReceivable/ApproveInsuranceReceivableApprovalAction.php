<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Illuminate\Validation\ValidationException;

class ApproveInsuranceReceivableApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly ApproveAccountingValidationAction $approveAccountingValidationAction,
        private readonly PerformLoanInquiryAction $loanInquiryAction,
        private readonly OperRepaymentAccount $operAccount,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter formation workflow.',
            ]);
        }

        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be approved.',
            ]);
        }

        $request = $this->activeRequestFor($insuranceReceivable);
        $this->assertCanApprove($insuranceReceivable, $request, $user);

        if ($request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION) {
            return $this->approveAccountingValidationAction->handle($insuranceReceivable, $request, $user, $notes);
        }

        if ($this->approvalService->currentPendingStep($request)->role_name === 'branch_approver') {
            $insuranceReceivable = $this->assertFreshOperAccount($insuranceReceivable, $user);
        }

        $this->approvalService->approveCurrentStep($request, $user, $notes);

        return $insuranceReceivable->refresh();
    }

    /**
     * BM approval requires the RPA-submitted OPER change to be approved in Fincloud already.
     */
    private function assertFreshOperAccount(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        $receivable = $this->loanInquiryAction->handle($insuranceReceivable, $user);

        try {
            $this->operAccount->assertMatches($receivable);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first().' Approve the RPA repayment account change in Fincloud first.';
            $receivable->forceFill(['last_error_message' => $message])->save();

            $this->stageLogger->log(
                receivable: $receivable,
                event: 'branch_approval_oper_check_blocked',
                fromStatus: $receivable->workflow_status,
                toStatus: $receivable->workflow_status,
                description: $message,
                metadata: [
                    'expected_oper_account' => $this->operAccount->expected($receivable),
                    'actual_repayment_account' => $receivable->saving_account_for_loan_repayment,
                ],
                actor: $user,
            );

            throw ValidationException::withMessages(['saving_account_for_loan_repayment' => $message]);
        }

        if ($receivable->last_error_message !== null) {
            $receivable->forceFill(['last_error_message' => null])->save();
        }

        return $receivable;
    }

    private function activeRequestFor(InsuranceReceivable $insuranceReceivable): ApprovalRequest
    {
        $request = $this->approvalService->latestActiveRequest($insuranceReceivable);

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active approval request not found.',
            ]);
        }

        return $request;
    }

    private function assertCanApprove(InsuranceReceivable $insuranceReceivable, ApprovalRequest $request, User $user): void
    {
        if (! $user->can('approveApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized approvers can approve this receivable.',
            ]);
        }

        $expectedStatus = match ($request->workflow_code) {
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            default => null,
        };

        if ($expectedStatus === null || $insuranceReceivable->workflow_status !== $expectedStatus) {
            throw ValidationException::withMessages([
                'approval' => 'This approval request is no longer pending.',
            ]);
        }
    }
}
