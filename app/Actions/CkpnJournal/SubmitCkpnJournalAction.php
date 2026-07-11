<?php

namespace App\Actions\CkpnJournal;

use App\Models\ApprovalRequest;
use App\Models\CkpnJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCkpnJournalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnJournal $journal, User $user, ?string $notes = null): CkpnJournal
    {
        $this->validateBatch(collect([$journal]), $user);

        return DB::transaction(function () use ($journal, $user, $notes): CkpnJournal {
            $locked = CkpnJournal::query()
                ->with('ckpnWorkpaper')
                ->whereKey($journal->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateBatch(collect([$locked]), $user);

            return $this->submitLocked($locked, $user, $notes)->refresh();
        });
    }

    /**
     * @param  iterable<CkpnJournal>  $journals
     * @return Collection<int, CkpnJournal>
     */
    public function handleMany(iterable $journals, User $user): Collection
    {
        $ids = collect($journals)
            ->map(fn (CkpnJournal $journal): int => (int) $journal->getKey())
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $selected = CkpnJournal::query()
            ->with('ckpnWorkpaper')
            ->whereKey($ids->all())
            ->orderBy('id')
            ->get();

        $this->assertAllSelectedJournalsLoaded($selected, $ids);
        $this->validateBatch($selected, $user);

        return DB::transaction(function () use ($ids, $user): Collection {
            $locked = CkpnJournal::query()
                ->with('ckpnWorkpaper')
                ->whereKey($ids->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedJournalsLoaded($locked, $ids);
            $this->validateBatch($locked, $user);

            return $locked
                ->map(fn (CkpnJournal $journal): CkpnJournal => $this->submitLocked($journal, $user)->refresh())
                ->values();
        });
    }

    /**
     * @param  Collection<int, CkpnJournal>  $journals
     * @param  Collection<int, int>  $ids
     */
    private function assertAllSelectedJournalsLoaded(Collection $journals, Collection $ids): void
    {
        if ($journals->count() === $ids->count()) {
            return;
        }

        throw ValidationException::withMessages([
            'records' => 'Selected CKPN Journals could not be loaded.',
        ]);
    }

    /**
     * @param  Collection<int, CkpnJournal>  $journals
     */
    private function validateBatch(Collection $journals, User $user): void
    {
        $journals->each->load('ckpnWorkpaper');

        $withoutWorkpaper = $journals->filter(fn (CkpnJournal $journal): bool => $journal->ckpnWorkpaper === null);

        if ($withoutWorkpaper->isNotEmpty()) {
            throw ValidationException::withMessages([
                'workpaper' => 'Selected CKPN Journals must have related CKPN Workpapers.',
            ]);
        }

        $periods = $journals
            ->map(fn (CkpnJournal $journal): ?string => $journal->ckpnWorkpaper?->period?->toDateString())
            ->unique()
            ->values();

        if ($periods->count() > 1) {
            throw ValidationException::withMessages([
                'period' => 'Selected CKPN Journals must have the same cutoff date.',
            ]);
        }

        $ineligible = $journals->filter(
            fn (CkpnJournal $journal): bool => ! in_array($journal->status, CkpnJournal::editableStatuses(), true)
        );

        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "{$ineligible->count()} selected CKPN Journals are not eligible for submission.",
            ]);
        }

        $unauthorized = $journals->filter(fn (CkpnJournal $journal): bool => ! $user->can('submit', $journal));

        if ($unauthorized->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can submit CKPN journals.',
            ]);
        }

        $withoutTotal = $journals->filter(fn (CkpnJournal $journal): bool => blank($journal->derivedTotalAmount()));

        if ($withoutTotal->isNotEmpty()) {
            throw ValidationException::withMessages([
                'total_amount' => 'CKPN journal amount could not be derived from the workpaper.',
            ]);
        }

        $withActiveApproval = $journals->filter(fn (CkpnJournal $journal): bool => $this->hasActiveJournalApproval($journal));

        if ($withActiveApproval->isNotEmpty()) {
            throw ValidationException::withMessages([
                'approval' => 'One or more selected CKPN Journals already have active approval requests.',
            ]);
        }
    }

    private function hasActiveJournalApproval(CkpnJournal $journal): bool
    {
        return $journal->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->exists();
    }

    private function submitLocked(CkpnJournal $journal, User $user, ?string $notes = null): CkpnJournal
    {
        $this->approvalService->submit(
            approvable: $journal,
            workflowCode: ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL,
            actor: $user,
            notes: $notes,
        );

        $journal->forceFill([
            'total_amount' => $journal->derivedTotalAmount(),
            'status' => CkpnJournal::STATUS_SUBMITTED,
        ])->save();

        return $journal;
    }
}
