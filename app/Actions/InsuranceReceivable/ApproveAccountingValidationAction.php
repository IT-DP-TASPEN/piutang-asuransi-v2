<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Validation\ValidationException;

class ApproveAccountingValidationAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly ProcessAccountingValidationInstallmentRepaymentAction $repaymentAction,
        private readonly PrepareAccountingValidationContractOutstandingAction $contractOutstandingAction,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, ApprovalRequest $request, User $user, ?string $notes = null): InsuranceReceivable
    {
        if (! $user->can('approveApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized approvers can approve Accounting Validation.',
            ]);
        }

        if ($insuranceReceivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION) {
            throw ValidationException::withMessages([
                'approval' => 'This approval request is no longer pending.',
            ]);
        }

        $this->assertAccountingRequest($request);
        $this->assertCanActOnApproval($request, $user);

        $businessDate = now('Asia/Jakarta')->toDateString();

        $receivable = $this->repaymentAction->handle($insuranceReceivable, $user);
        $this->contractOutstandingAction->handle($receivable, $user, $businessDate);
        $this->approvalService->approveCurrentStep($request->refresh(), $user, $notes);

        return $insuranceReceivable->refresh();
    }

    private function assertAccountingRequest(ApprovalRequest $request): void
    {
        if (
            $request->workflow_code !== ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION
            || $request->status !== ApprovalRequest::STATUS_SUBMITTED
        ) {
            throw ValidationException::withMessages([
                'approval' => 'Active Accounting Validation approval request not found.',
            ]);
        }
    }

    private function assertCanActOnApproval(ApprovalRequest $request, User $user): void
    {
        if (! $this->approvalService->canActOnCurrentStep($request, $user)) {
            throw ValidationException::withMessages([
                'approval' => 'You cannot act on this Accounting Validation step.',
            ]);
        }
    }
}
