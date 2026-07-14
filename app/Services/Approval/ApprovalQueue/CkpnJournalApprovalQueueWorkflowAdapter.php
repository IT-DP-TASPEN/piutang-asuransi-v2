<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\CkpnJournal\ApproveCkpnJournalAction;
use App\Actions\CkpnJournal\RejectCkpnJournalAction;
use App\Actions\CkpnJournal\ReturnCkpnJournalAction;
use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use App\Models\ApprovalRequest;
use App\Models\CkpnJournal;
use App\Models\User;

class CkpnJournalApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'CKPN Journal';
    }

    public function summary(ApprovalRequest $request): string
    {
        $journal = $this->journal($request)?->loadMissing(['branchOffice', 'ckpnWorkpaper']);

        return $journal
            ? collect([$journal->journal_date?->toDateString(), $this->amountLabel($request)])->filter()->join(' - ')
            : 'CKPN journal';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->journal($request) ? 'CKPN Journal #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $journal = $this->journal($request)?->loadMissing(['branchOffice', 'ckpnWorkpaper.branchOffice']);

        return $journal?->branchOffice?->branch_name
            ?? $journal?->ckpnWorkpaper?->branchOffice?->branch_name
            ?? 'Central';
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->journal($request)?->total_amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $journal = $this->journal($request);

        return $journal ? CkpnJournalResource::getUrl('view', ['record' => $journal]) : null;
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
        app(ApproveCkpnJournalAction::class)->handle($this->journalOrFail($request), $user, $notes);
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ReturnCkpnJournalAction::class)->handle($this->journalOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectCkpnJournalAction::class)->handle($this->journalOrFail($request), $user, $notes);
    }

    public function confirmationDescription(string $action, ApprovalRequest $request): ?string
    {
        return $action === 'approve'
            ? 'Approval uses the existing CKPN Journal flow and may queue GL-to-GL execution.'
            : null;
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $journal = $this->journal($request)?->loadMissing(['branchOffice', 'ckpnWorkpaper']);

        if (! $journal) {
            return [];
        }

        return [
            'Cutoff date' => $journal->ckpnWorkpaper?->period?->toDateString(),
            'Journal date' => $journal->journal_date?->toDateString(),
            'Branch' => $this->branchLabel($request),
            'Journal amount' => $this->money($journal->total_amount),
            'Debit account' => $this->value($journal->debit_account),
            'Credit account' => $this->value($journal->credit_account),
            'Description' => $this->value($journal->description),
            'Status' => CkpnJournal::statusOptions()[$journal->status] ?? $journal->status,
        ];
    }

    private function canMutate(ApprovalRequest $request, User $user, string $ability): bool
    {
        $journal = $this->journal($request);

        return $journal instanceof CkpnJournal
            && $journal->status === CkpnJournal::STATUS_SUBMITTED
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can($ability, $journal);
    }

    private function journal(ApprovalRequest $request): ?CkpnJournal
    {
        return $this->approvable($request, CkpnJournal::class);
    }

    private function journalOrFail(ApprovalRequest $request): CkpnJournal
    {
        return $this->journal($request) ?? throw new \RuntimeException('CKPN journal not found.');
    }
}
