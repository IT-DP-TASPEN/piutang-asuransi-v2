<?php

namespace App\Actions\Ckpn;

use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaper;
use Illuminate\Validation\ValidationException;

class ValidateCkpnJournalCreationAction
{
    /**
     * @return list<string>
     */
    public static function pendingAdjustmentStatuses(): array
    {
        return [
            CkpnAdjustment::STATUS_DRAFT,
            CkpnAdjustment::STATUS_SUBMITTED,
            CkpnAdjustment::STATUS_RETURNED,
        ];
    }

    public function handle(CkpnWorkpaper $workpaper): void
    {
        $pending = $workpaper->adjustments()
            ->whereIn('status', self::pendingAdjustmentStatuses())
            ->orderBy('id')
            ->get(['id', 'status']);

        if ($pending->isEmpty()) {
            return;
        }

        $summary = $pending
            ->take(10)
            ->map(fn (CkpnAdjustment $adjustment): string => "#{$adjustment->id} ({$adjustment->status})")
            ->join(', ');

        $suffix = $pending->count() > 10 ? ', ...' : '';

        throw ValidationException::withMessages([
            'ckpn_adjustments' => "Cannot create CKPN Journal because {$pending->count()} CKPN Adjustments are still pending: {$summary}{$suffix}.",
        ]);
    }

    public function pendingCount(CkpnWorkpaper $workpaper): int
    {
        return $workpaper->adjustments()
            ->whereIn('status', self::pendingAdjustmentStatuses())
            ->count();
    }
}
