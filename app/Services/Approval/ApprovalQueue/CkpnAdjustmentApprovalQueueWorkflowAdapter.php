<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\CkpnAdjustment\ApproveCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\RejectCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\ReturnCkpnAdjustmentAction;
use App\Filament\Resources\CkpnAdjustments\CkpnAdjustmentResource;
use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\User;

class CkpnAdjustmentApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'CKPN Adjustment';
    }

    public function summary(ApprovalRequest $request): string
    {
        $adjustment = $this->adjustment($request)?->loadMissing(['ckpnWorkpaper', 'ckpnWorkpaperItem']);

        return $adjustment
            ? collect([
                $adjustment->ckpnWorkpaper?->period?->toDateString(),
                $adjustment->ckpnWorkpaperItem?->customer_name,
                $this->amountLabel($request),
            ])->filter()->join(' - ')
            : 'CKPN adjustment';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->adjustment($request) ? 'CKPN Adjustment #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $adjustment = $this->adjustment($request)?->loadMissing('ckpnWorkpaper.branchOffice');

        return $adjustment?->ckpnWorkpaper?->branchOffice?->branch_name ?? 'Central';
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->adjustment($request)?->requested_adjusted_ckpn_amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $adjustment = $this->adjustment($request);

        return $adjustment ? CkpnAdjustmentResource::getUrl('edit', ['record' => $adjustment]) : null;
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
        app(ApproveCkpnAdjustmentAction::class)->handle($this->adjustmentOrFail($request), $user, $notes);
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ReturnCkpnAdjustmentAction::class)->handle($this->adjustmentOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectCkpnAdjustmentAction::class)->handle($this->adjustmentOrFail($request), $user, $notes);
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return in_array($action, ['return', 'reject'], true);
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $adjustment = $this->adjustment($request)?->loadMissing(['ckpnWorkpaper', 'ckpnWorkpaperItem']);

        if (! $adjustment) {
            return [];
        }

        return [
            'Cutoff date' => $adjustment->ckpnWorkpaper?->period?->toDateString(),
            'Branch' => $this->branchLabel($request),
            'Customer' => $this->value($adjustment->ckpnWorkpaperItem?->customer_name),
            'Loan account' => $this->value($adjustment->ckpnWorkpaperItem?->loan_account_number),
            'Calculated CKPN' => $this->money($adjustment->calculated_ckpn_amount),
            'Requested CKPN' => $this->money($adjustment->requested_adjusted_ckpn_amount),
            'Reason' => $this->value($adjustment->reason),
            'Status' => CkpnAdjustment::statusOptions()[$adjustment->status] ?? $adjustment->status,
        ];
    }

    private function canMutate(ApprovalRequest $request, User $user, string $ability): bool
    {
        $adjustment = $this->adjustment($request);

        return $adjustment instanceof CkpnAdjustment
            && $adjustment->status === CkpnAdjustment::STATUS_SUBMITTED
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can($ability, $adjustment);
    }

    private function adjustment(ApprovalRequest $request): ?CkpnAdjustment
    {
        return $this->approvable($request, CkpnAdjustment::class);
    }

    private function adjustmentOrFail(ApprovalRequest $request): CkpnAdjustment
    {
        return $this->adjustment($request) ?? throw new \RuntimeException('CKPN adjustment not found.');
    }
}
