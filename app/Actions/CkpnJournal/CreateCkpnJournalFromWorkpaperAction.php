<?php

namespace App\Actions\CkpnJournal;

use App\Actions\Ckpn\ValidateCkpnJournalCreationAction;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCkpnJournalFromWorkpaperAction
{
    public function __construct(
        private readonly ValidateCkpnJournalCreationAction $validateCkpnJournalCreationAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(CkpnWorkpaper $workpaper, User $user, array $data = []): CkpnJournal
    {
        $this->validateBatch(collect([$workpaper]), $user);

        return DB::transaction(function () use ($workpaper, $user, $data): CkpnJournal {
            $locked = CkpnWorkpaper::query()
                ->whereKey($workpaper->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateBatch(collect([$locked]), $user);

            return $this->createLocked($locked, $user, $data);
        });
    }

    /**
     * @param  iterable<CkpnWorkpaper>  $workpapers
     * @param  array<string, mixed>  $data
     * @return Collection<int, CkpnJournal>
     */
    public function handleMany(iterable $workpapers, User $user, array $data = []): Collection
    {
        $ids = collect($workpapers)
            ->map(fn (CkpnWorkpaper $workpaper): int => (int) $workpaper->getKey())
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $selected = CkpnWorkpaper::query()
            ->whereKey($ids->all())
            ->orderBy('id')
            ->get();

        $this->assertAllSelectedWorkpapersLoaded($selected, $ids);
        $this->validateBatch($selected, $user);

        return DB::transaction(function () use ($ids, $user, $data): Collection {
            $locked = CkpnWorkpaper::query()
                ->whereKey($ids->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedWorkpapersLoaded($locked, $ids);
            $this->validateBatch($locked, $user);

            return $locked
                ->map(fn (CkpnWorkpaper $workpaper): CkpnJournal => $this->createLocked($workpaper, $user, $data)->refresh())
                ->values();
        });
    }

    /**
     * @param  Collection<int, CkpnWorkpaper>  $workpapers
     * @param  Collection<int, int>  $ids
     */
    private function assertAllSelectedWorkpapersLoaded(Collection $workpapers, Collection $ids): void
    {
        if ($workpapers->count() === $ids->count()) {
            return;
        }

        throw ValidationException::withMessages([
            'records' => 'Selected CKPN Workpapers could not be loaded.',
        ]);
    }

    /**
     * @param  Collection<int, CkpnWorkpaper>  $workpapers
     */
    private function validateBatch(Collection $workpapers, User $user): void
    {
        $periods = $workpapers
            ->map(fn (CkpnWorkpaper $workpaper): ?string => $workpaper->period?->toDateString())
            ->unique()
            ->values();

        if ($periods->count() > 1) {
            throw ValidationException::withMessages([
                'period' => 'Selected CKPN Workpapers must have the same cutoff date.',
            ]);
        }

        $ineligible = $workpapers->filter(
            fn (CkpnWorkpaper $workpaper): bool => $workpaper->status !== CkpnWorkpaper::STATUS_APPROVED
        );

        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "{$ineligible->count()} selected CKPN Workpapers are not eligible for CKPN Journal creation.",
            ]);
        }

        $unauthorized = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => ! $user->can('createJournal', $workpaper));

        if ($unauthorized->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can create CKPN journals.',
            ]);
        }

        $workpapers->each(fn (CkpnWorkpaper $workpaper): mixed => $this->validateCkpnJournalCreationAction->handle($workpaper));

        $withBlockingJournals = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => $this->hasBlockingJournal($workpaper));

        if ($withBlockingJournals->isNotEmpty()) {
            throw ValidationException::withMessages([
                'ckpn_journal' => 'One or more selected CKPN Workpapers already have blocking CKPN Journals.',
            ]);
        }
    }

    private function hasBlockingJournal(CkpnWorkpaper $workpaper): bool
    {
        return $workpaper->journals()
            ->whereIn('status', CkpnJournal::blockingWorkpaperJournalStatuses())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLocked(CkpnWorkpaper $workpaper, User $user, array $data = []): CkpnJournal
    {
        return $workpaper->journals()->create($this->journalAttributes($workpaper, $user, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function journalAttributes(CkpnWorkpaper $workpaper, User $user, array $data): array
    {
        $description = $data['description'] ?? null;

        if ($workpaper->items()->whereNotNull('adjusted_ckpn_amount')->exists()) {
            $description = collect([
                $description,
                'Includes approved CKPN adjustments.',
            ])->filter()->join("\n");
        }

        return [
            'branch_office_id' => $workpaper->branch_office_id,
            'journal_date' => $data['journal_date'] ?? now()->toDateString(),
            'total_amount' => $workpaper->total_effective_ckpn_amount,
            'debit_account' => $data['debit_account'] ?? null,
            'credit_account' => $data['credit_account'] ?? null,
            'debit_narrative' => $data['debit_narrative'] ?? null,
            'credit_narrative' => $data['credit_narrative'] ?? null,
            'description' => $description,
            'status' => CkpnJournal::STATUS_DRAFT,
            'created_by' => $user->id,
        ];
    }
}
