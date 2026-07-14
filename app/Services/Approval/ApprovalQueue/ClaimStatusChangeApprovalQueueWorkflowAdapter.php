<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\ReturnClaimStatusChangeRequestAction;
use App\Filament\Resources\ClaimStatusChangeRequests\ClaimStatusChangeRequestResource;
use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;

class ClaimStatusChangeApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'Claim Status Update';
    }

    public function summary(ApprovalRequest $request): string
    {
        $change = $this->changeRequest($request)?->loadMissing(['insuranceReceivable', 'fromClaimStatus', 'toClaimStatus']);

        return $change
            ? collect([
                $change->insuranceReceivable?->customer_name,
                ($change->fromClaimStatus?->name ?: '-').' -> '.($change->toClaimStatus?->name ?: '-'),
            ])->filter()->join(' - ')
            : 'Claim status update';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->changeRequest($request) ? 'Claim Status #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $change = $this->changeRequest($request)?->loadMissing('insuranceReceivable.branchOffice');

        return $change?->insuranceReceivable?->branchOffice?->branch_name
            ?? $change?->insuranceReceivable?->branch_code;
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->changeRequest($request)?->insuranceReceivable?->receivable_amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $change = $this->changeRequest($request);

        return $change ? ClaimStatusChangeRequestResource::getUrl('edit', ['record' => $change]) : null;
    }

    public function canApprove(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'approve');
    }

    public function canReturn(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'returnRequest');
    }

    public function canReject(ApprovalRequest $request, User $user): bool
    {
        return $this->canMutate($request, $user, 'reject');
    }

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ApproveClaimStatusChangeRequestAction::class)->handle($this->changeRequestOrFail($request), $user, $notes);
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ReturnClaimStatusChangeRequestAction::class)->handle($this->changeRequestOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectClaimStatusChangeRequestAction::class)->handle($this->changeRequestOrFail($request), $user, $notes);
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return in_array($action, ['return', 'reject'], true);
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $change = $this->changeRequest($request)?->loadMissing([
            'insuranceReceivable',
            'fromClaimStatus',
            'toClaimStatus',
            'requester',
        ]);

        if (! $change) {
            return [];
        }

        return [
            'Customer' => $this->value($change->insuranceReceivable?->customer_name),
            'Loan account' => $this->value($change->insuranceReceivable?->loan_account_number),
            'Branch' => $this->branchLabel($request),
            'From status' => $change->fromClaimStatus?->name,
            'To status' => $change->toClaimStatus?->name,
            'Reason' => $this->value($change->reason),
            'Request status' => ClaimStatusChangeRequest::statusOptions()[$change->status] ?? $change->status,
        ];
    }

    private function canMutate(ApprovalRequest $request, User $user, string $ability): bool
    {
        $change = $this->changeRequest($request);

        return $change instanceof ClaimStatusChangeRequest
            && $change->status === ClaimStatusChangeRequest::STATUS_SUBMITTED
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can($ability, $change);
    }

    private function changeRequest(ApprovalRequest $request): ?ClaimStatusChangeRequest
    {
        return $this->approvable($request, ClaimStatusChangeRequest::class);
    }

    private function changeRequestOrFail(ApprovalRequest $request): ClaimStatusChangeRequest
    {
        return $this->changeRequest($request) ?? throw new \RuntimeException('Claim status change request not found.');
    }
}
