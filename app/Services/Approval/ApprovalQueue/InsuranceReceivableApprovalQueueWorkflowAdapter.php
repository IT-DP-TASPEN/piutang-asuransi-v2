<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;

class InsuranceReceivableApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return $request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION
            ? 'Accounting Validation'
            : 'Insurance Receivable Initial Approval';
    }

    public function summary(ApprovalRequest $request): string
    {
        $receivable = $this->receivable($request);

        return $receivable
            ? collect([$receivable->customer_name, $receivable->loan_account_number])->filter()->join(' - ')
            : 'Insurance receivable';
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
        $receivable = $this->receivable($request);

        return $this->money($receivable?->receivable_amount ?? $receivable?->loan_outstanding);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $receivable = $this->receivable($request);

        return $receivable ? InsuranceReceivableResource::getUrl('view', ['record' => $receivable]) : null;
    }

    public function canApprove(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'approveApproval');
    }

    public function canReturn(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'returnApproval');
    }

    public function canReject(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'rejectApproval');
    }

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ApproveInsuranceReceivableApprovalAction::class)->handle($this->receivableOrFail($request), $user, $notes);
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ReturnInsuranceReceivableApprovalAction::class)->handle($this->receivableOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectInsuranceReceivableApprovalAction::class)->handle($this->receivableOrFail($request), $user, $notes);
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return in_array($action, ['return', 'reject'], true);
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $receivable = $this->receivable($request)?->loadMissing(['branchOffice', 'insuranceCompany', 'claimStatus']);

        if (! $receivable) {
            return [];
        }

        return [
            'Customer' => $this->value($receivable->customer_name),
            'CIF' => $this->value($receivable->cif_no),
            'Loan account' => $this->value($receivable->loan_account_number),
            'Branch' => $this->branchLabel($request),
            'Insurance company' => $receivable->insuranceCompany?->name,
            'Claim status' => $receivable->claimStatus?->name,
            'Date of death' => $receivable->date_of_death?->toDateString(),
            'Loan outstanding' => $this->money($receivable->loan_outstanding),
            'Contract outstanding' => $this->money($receivable->contract_outstanding_amount),
            'Receivable amount' => $this->money($receivable->receivable_amount),
            'Remaining receivable' => $this->money($receivable->remaining_receivable_amount),
            'Workflow status' => InsuranceReceivable::workflowStatusOptions()[$receivable->workflow_status] ?? $receivable->workflow_status,
            'System status' => InsuranceReceivable::systemStatusOptions()[$receivable->system_status] ?? $receivable->system_status,
        ];
    }

    private function canMutate(ApprovalRequest $request, User $user, string $ability): bool
    {
        $receivable = $this->receivable($request);

        return $receivable instanceof InsuranceReceivable
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can($ability, $receivable)
            && $this->expectedWorkflowStatus($request) === $receivable->workflow_status;
    }

    private function expectedWorkflowStatus(ApprovalRequest $request): ?string
    {
        return match ($request->workflow_code) {
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            default => null,
        };
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
