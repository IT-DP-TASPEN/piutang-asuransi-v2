<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Validation\ValidationException;

class RetryInstallmentRepaymentAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly ProcessAccountingValidationInstallmentRepaymentAction $repaymentAction,
        private readonly PrepareAccountingValidationContractOutstandingAction $contractOutstandingAction,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        $request = $this->activeAccountingRequest($insuranceReceivable);
        $this->assertCanActOnApproval($request, $user);

        $businessDate = now('Asia/Jakarta')->toDateString();
        $receivable = $this->repaymentAction->handle($insuranceReceivable, $user, retry: true);
        $this->contractOutstandingAction->handle($receivable, $user, $businessDate);
        $this->approvalService->approveCurrentStep($request->refresh(), $user, $notes);

        return $insuranceReceivable->refresh();
    }

    private function activeAccountingRequest(InsuranceReceivable $insuranceReceivable): ApprovalRequest
    {
        $request = $insuranceReceivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active Accounting Validation approval request not found.',
            ]);
        }

        return $request;
    }

    private function assertCanActOnApproval(ApprovalRequest $request, User $user): void
    {
        $step = $request->steps()
            ->where('status', ApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->first();

        if (! $step instanceof ApprovalStep) {
            throw ValidationException::withMessages([
                'approval' => 'No pending approval step found.',
            ]);
        }

        if ($user->hasRole('super_admin')) {
            return;
        }

        if ($step->assigned_user_id !== null && $step->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'approval' => 'Approval step is assigned to another user.',
            ]);
        }

        if ($step->role_name !== null && ! $user->hasRole($step->role_name)) {
            throw ValidationException::withMessages([
                'approval' => "Approval step requires role {$step->role_name}.",
            ]);
        }
    }
}
