<?php

namespace App\Actions\CkpnWorkpaper;

use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCkpnWorkpaperAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        $this->validateBatch(collect([$workpaper]), $user);

        return DB::transaction(function () use ($workpaper, $user, $notes): CkpnWorkpaper {
            $locked = CkpnWorkpaper::query()
                ->whereKey($workpaper->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateBatch(collect([$locked]), $user);

            return $this->submitLocked($locked, $user, $notes)->refresh();
        });
    }

    /**
     * @param  iterable<CkpnWorkpaper>  $workpapers
     * @return Collection<int, CkpnWorkpaper>
     */
    public function handleMany(iterable $workpapers, User $user): Collection
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

        return DB::transaction(function () use ($ids, $user): Collection {
            $locked = CkpnWorkpaper::query()
                ->whereKey($ids->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedWorkpapersLoaded($locked, $ids);
            $this->validateBatch($locked, $user);

            return $locked
                ->map(fn (CkpnWorkpaper $workpaper): CkpnWorkpaper => $this->submitLocked($workpaper, $user)->refresh())
                ->values();
        });
    }

    /**
     * @param  Collection<int, CkpnWorkpaper>  $workpapers
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
        $workpapers->each->loadCount('items');

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
            fn (CkpnWorkpaper $workpaper): bool => ! in_array($workpaper->status, CkpnWorkpaper::submittableStatuses(), true)
        );

        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "{$ineligible->count()} selected CKPN Workpapers are not eligible for submission.",
            ]);
        }

        $unauthorized = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => ! $user->can('submit', $workpaper));

        if ($unauthorized->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can submit CKPN workpapers.',
            ]);
        }

        $withoutItems = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => (int) $workpaper->items_count < 1);

        if ($withoutItems->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'CKPN workpaper must have generated items before submission.',
            ]);
        }

        $withActiveApproval = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => $this->hasActiveWorkpaperApproval($workpaper));

        if ($withActiveApproval->isNotEmpty()) {
            throw ValidationException::withMessages([
                'approval' => 'One or more selected CKPN Workpapers already have active approval requests.',
            ]);
        }
    }

    private function hasActiveWorkpaperApproval(CkpnWorkpaper $workpaper): bool
    {
        return $workpaper->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->exists();
    }

    private function submitLocked(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        $this->approvalService->submit(
            approvable: $workpaper,
            workflowCode: ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER,
            actor: $user,
            notes: $notes,
        );

        $workpaper->forceFill([
            'status' => CkpnWorkpaper::STATUS_SUBMITTED,
        ])->save();

        return $workpaper;
    }
}
