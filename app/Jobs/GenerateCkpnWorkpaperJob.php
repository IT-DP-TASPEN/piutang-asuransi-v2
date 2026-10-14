<?php

namespace App\Jobs;

use App\Actions\Ckpn\GenerateMonthlyCkpnWorkpaperAction;
use App\Models\CkpnWorkpaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateCkpnWorkpaperJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $ckpnWorkpaperId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(GenerateMonthlyCkpnWorkpaperAction $action): void
    {
        $shouldProcess = DB::transaction(function (): bool {
            $workpaper = CkpnWorkpaper::query()
                ->whereKey($this->ckpnWorkpaperId)
                ->lockForUpdate()
                ->first();

            if (! $workpaper instanceof CkpnWorkpaper) {
                return false;
            }

            if (! in_array($workpaper->status, CkpnWorkpaper::jobGenerationStatuses(), true)) {
                return false;
            }

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_GENERATION_PROCESSING,
                'last_error_message' => null,
            ])->save();

            return true;
        });

        if (! $shouldProcess) {
            return;
        }

        try {
            $action->handle(CkpnWorkpaper::query()->findOrFail($this->ckpnWorkpaperId));
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof Throwable) {
            $this->markFailed($exception);
        }
    }

    private function markFailed(Throwable $exception): void
    {
        // A late failed() must not knock a workpaper that another run already generated back to failed.
        CkpnWorkpaper::query()
            ->whereKey($this->ckpnWorkpaperId)
            ->whereIn('status', [CkpnWorkpaper::STATUS_GENERATION_QUEUED, CkpnWorkpaper::STATUS_GENERATION_PROCESSING])
            ->update([
                'status' => CkpnWorkpaper::STATUS_GENERATION_FAILED,
                'last_error_message' => $exception->getMessage() ?: $exception::class,
            ]);
    }
}
