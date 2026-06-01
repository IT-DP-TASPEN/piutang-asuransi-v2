<?php

namespace App\Actions\Ckpn;

use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Ckpn\CkpnWorkpaperReadinessValidator;
use Illuminate\Support\Facades\DB;

class CreateCkpnWorkpaperAction
{
    public function __construct(
        private readonly CkpnWorkpaperReadinessValidator $readinessValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user): CkpnWorkpaper
    {
        return DB::transaction(function () use ($data, $user): CkpnWorkpaper {
            $period = CkpnWorkpaper::normalizePeriod($data['period'])->toDateString();
            $branchOfficeId = filled($data['branch_office_id'] ?? null)
                ? (int) $data['branch_office_id']
                : null;

            $this->readinessValidator->assertNoDuplicateWorkpaper($period, $branchOfficeId);
            $this->readinessValidator->assertNoPendingInsuranceReceivables($period, $branchOfficeId);

            $workpaper = CkpnWorkpaper::query()->create([
                ...$data,
                'period' => $period,
                'branch_office_id' => $branchOfficeId,
                'branch_scope_key' => CkpnWorkpaper::branchScopeKeyFor($branchOfficeId),
                'status' => CkpnWorkpaper::STATUS_GENERATION_QUEUED,
                'created_by' => $user->id,
                'last_error_message' => null,
                'generated_at' => null,
            ]);

            GenerateCkpnWorkpaperJob::dispatch($workpaper->id)->afterCommit();

            return $workpaper;
        });
    }
}
