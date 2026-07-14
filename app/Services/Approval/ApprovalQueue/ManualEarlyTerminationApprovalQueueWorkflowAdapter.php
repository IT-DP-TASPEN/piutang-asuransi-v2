<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\InsuranceReceivable\ResolveEarlyTerminationManuallyAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;

class ManualEarlyTerminationApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'Manual Early Termination Verification';
    }

    public function summary(ApprovalRequest $request): string
    {
        $receivable = $this->receivable($request);

        return $receivable
            ? collect([$receivable->customer_name, $receivable->loan_account_number])->filter()->join(' - ')
            : 'Manual Early Termination';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->receivable($request) ? 'IR #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $receivable = $this->receivable($request)?->loadMissing('branchOffice');

        return $receivable?->branchOffice?->branch_name ?? $receivable?->branch_code;
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->receivable($request)?->remaining_receivable_amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $receivable = $this->receivable($request);

        return $receivable ? InsuranceReceivableResource::getUrl('view', ['record' => $receivable]) : null;
    }

    public function canApprove(ApprovalRequest $request, User $user): bool
    {
        $receivable = $this->receivable($request);

        return $receivable instanceof InsuranceReceivable
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can('resolveEarlyTermination', $receivable)
            && $receivable->manualEarlyTerminationSubmitted()
            && $receivable->canResolveEarlyTermination();
    }

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ResolveEarlyTerminationManuallyAction::class)->handle($this->receivableOrFail($request), $user, $notes);
    }

    public function confirmationDescription(string $action, ApprovalRequest $request): ?string
    {
        return $action === 'approve'
            ? 'Verification may call Core Banking loan inquiry before resolving the Early Termination.'
            : null;
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $receivable = $this->receivable($request)?->loadMissing(['branchOffice', 'insuranceCompany']);

        if (! $receivable) {
            return [];
        }

        return [
            'Customer' => $this->value($receivable->customer_name),
            'Loan account' => $this->value($receivable->loan_account_number),
            'Branch' => $this->branchLabel($request),
            'Insurance company' => $receivable->insuranceCompany?->name,
            'Remaining receivable' => $this->money($receivable->remaining_receivable_amount),
            'Repayment saving account' => $this->value($receivable->saving_account_for_loan_repayment),
            'Workflow status' => InsuranceReceivable::workflowStatusOptions()[$receivable->workflow_status] ?? $receivable->workflow_status,
            'System status' => InsuranceReceivable::systemStatusOptions()[$receivable->system_status] ?? $receivable->system_status,
        ];
    }

    private function receivable(ApprovalRequest $request): ?InsuranceReceivable
    {
        return $this->approvable($request, InsuranceReceivable::class);
    }

    private function receivableOrFail(ApprovalRequest $request): InsuranceReceivable
    {
        return $this->receivable($request) ?? throw new \RuntimeException('Insurance receivable not found.');
    }
}
