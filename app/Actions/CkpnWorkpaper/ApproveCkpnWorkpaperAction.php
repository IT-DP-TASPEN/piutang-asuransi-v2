<?php

namespace App\Actions\CkpnWorkpaper;

use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCkpnWorkpaperAction
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

            return $this->approveLocked($locked, $user, $notes)->refresh();
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
                ->map(fn (CkpnWorkpaper $workpaper): CkpnWorkpaper => $this->approveLocked($workpaper, $user)->refresh())
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
            fn (CkpnWorkpaper $workpaper): bool => $workpaper->status !== CkpnWorkpaper::STATUS_SUBMITTED
        );

        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "{$ineligible->count()} selected CKPN Workpapers are not eligible for approval.",
            ]);
        }

        $unauthorized = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => ! $user->can('approve', $workpaper));

        if ($unauthorized->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can approve CKPN workpapers.',
            ]);
        }

        $withoutActiveApproval = $workpapers->filter(fn (CkpnWorkpaper $workpaper): bool => $this->activeApprovalRequestCount($workpaper) !== 1);

        if ($withoutActiveApproval->isNotEmpty()) {
            throw ValidationException::withMessages([
                'approval' => 'One or more selected CKPN Workpapers do not have active workpaper approval requests.',
            ]);
        }
    }

    private function activeApprovalRequestCount(CkpnWorkpaper $workpaper): int
    {
        return $workpaper->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->count();
    }

    private function approveLocked(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        $approvalRequest = $this->activeApprovalRequestFor($workpaper);
        $approvalRequest = $this->approvalService->approveCurrentStep($approvalRequest, $user, $notes);

        if ($approvalRequest->status === ApprovalRequest::STATUS_APPROVED) {
            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();
        }

        return $workpaper;
    }

    private function activeApprovalRequestFor(CkpnWorkpaper $workpaper): ApprovalRequest
    {
        $approvalRequest = $this->approvalService->latestActiveRequest($workpaper, ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER);

        if (! $approvalRequest instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active CKPN workpaper approval request not found.',
            ]);
        }

        return $approvalRequest;
    }
}
