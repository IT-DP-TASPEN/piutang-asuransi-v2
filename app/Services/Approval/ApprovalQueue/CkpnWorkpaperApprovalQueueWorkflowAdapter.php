<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\RejectCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\ReturnCkpnWorkpaperAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;

class CkpnWorkpaperApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'CKPN Workpaper';
    }

    public function summary(ApprovalRequest $request): string
    {
        $workpaper = $this->workpaper($request)?->loadMissing('branchOffice');

        return $workpaper
            ? collect([$workpaper->period?->toDateString(), $this->branchLabel($request)])->filter()->join(' - ')
            : 'CKPN workpaper';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->workpaper($request) ? 'CKPN Workpaper #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $workpaper = $this->workpaper($request)?->loadMissing('branchOffice');

        return $workpaper?->branchOffice?->branch_name ?? 'Central';
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->workpaper($request)?->total_effective_ckpn_amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $workpaper = $this->workpaper($request);

        return $workpaper ? CkpnWorkpaperResource::getUrl('view', ['record' => $workpaper]) : null;
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
        app(ApproveCkpnWorkpaperAction::class)->handle($this->workpaperOrFail($request), $user, $notes);
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ReturnCkpnWorkpaperAction::class)->handle($this->workpaperOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectCkpnWorkpaperAction::class)->handle($this->workpaperOrFail($request), $user, $notes);
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return in_array($action, ['return', 'reject'], true);
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $workpaper = $this->workpaper($request)?->loadMissing('branchOffice');

        if (! $workpaper) {
            return [];
        }

        return [
            'Cutoff date' => $workpaper->period?->toDateString(),
            'Branch' => $this->branchLabel($request),
            'Total receivable' => $this->money($workpaper->total_receivable_amount),
            'Calculated CKPN' => $this->money($workpaper->total_calculated_ckpn_amount),
            'Adjustment delta' => $this->money($workpaper->total_adjustment_delta),
            'Effective CKPN' => $this->money($workpaper->total_effective_ckpn_amount),
            'Generated at' => $workpaper->generated_at?->toDateTimeString(),
            'Status' => CkpnWorkpaper::statusOptions()[$workpaper->status] ?? $workpaper->status,
        ];
    }

    private function canMutate(ApprovalRequest $request, User $user, string $ability): bool
    {
        $workpaper = $this->workpaper($request);

        return $workpaper instanceof CkpnWorkpaper
            && $workpaper->status === CkpnWorkpaper::STATUS_SUBMITTED
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can($ability, $workpaper);
    }

    private function workpaper(ApprovalRequest $request): ?CkpnWorkpaper
    {
        return $this->approvable($request, CkpnWorkpaper::class);
    }

    private function workpaperOrFail(ApprovalRequest $request): CkpnWorkpaper
    {
        return $this->workpaper($request) ?? throw new \RuntimeException('CKPN workpaper not found.');
    }
}
