<?php

namespace App\Actions\Ckpn;

use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\BranchOffice;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Ckpn\CkpnWorkpaperReadinessValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAllBranchCkpnWorkpapersAction
{
    public function __construct(
        private readonly CkpnWorkpaperReadinessValidator $readinessValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, CkpnWorkpaper>
     */
    public function handle(array $data, User $user): Collection
    {
        if (! $user->can('create', CkpnWorkpaper::class)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can create CKPN workpapers.',
            ]);
        }

        $period = CkpnWorkpaper::normalizePeriod($data['period'])->toDateString();
        $lock = Cache::lock("ckpn-workpapers:bulk-create-all-branches:{$period}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'period' => "CKPN Workpapers are already being created for cutoff date {$period}.",
            ]);
        }

        try {
            $branches = $this->targetBranches();

            if ($branches->isEmpty()) {
                throw ValidationException::withMessages([
                    'branch_office_id' => 'No active branches are available for CKPN Workpaper creation.',
                ]);
            }

            try {
                return DB::transaction(function () use ($period, $branches, $user): Collection {
                    $this->readinessValidator->assertNoPendingInsuranceReceivables($period, null);
                    $this->readinessValidator->assertNoPendingClaimStatusUpdates($period, null);
                    $this->readinessValidator->assertNoDuplicateWorkpapersForBranches($period, $branches);

                    $workpapers = $branches->map(fn (BranchOffice $branch): CkpnWorkpaper => CkpnWorkpaper::query()->create(
                        CkpnWorkpaper::queuedCreationAttributes($period, (int) $branch->id, $user->id),
                    ));

                    $workpapers->each(function (CkpnWorkpaper $workpaper): void {
                        GenerateCkpnWorkpaperJob::dispatch($workpaper->id)->afterCommit();
                    });

                    return $workpapers;
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'period' => "Cannot create CKPN Workpapers because one or more branches already have workpapers for cutoff date {$period}.",
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return Collection<int, BranchOffice>
     */
    private function targetBranches(): Collection
    {
        return BranchOffice::query()
            ->where('is_active', true)
            ->where('branch_code', '!=', '000')
            ->orderBy('branch_code')
            ->orderBy('id')
            ->get();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
