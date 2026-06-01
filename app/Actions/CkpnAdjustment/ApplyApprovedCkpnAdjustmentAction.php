<?php

namespace App\Actions\CkpnAdjustment;

use App\Actions\Ckpn\RecalculateCkpnWorkpaperTotalsAction;
use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaperItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyApprovedCkpnAdjustmentAction
{
    public function __construct(
        private readonly RecalculateCkpnWorkpaperTotalsAction $recalculateTotals,
    ) {}

    public function handle(CkpnAdjustment $adjustment, User $user): CkpnAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user): CkpnAdjustment {
            $adjustment = CkpnAdjustment::query()
                ->where('id', $adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = CkpnWorkpaperItem::query()
                ->where('id', $adjustment->ckpn_workpaper_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $workpaper = $item->ckpnWorkpaper()
                ->lockForUpdate()
                ->firstOrFail();

            if ($workpaper->journals()->exists() || $workpaper->generatedExports()->exists() || $workpaper->glToGlTransactions()->exists()) {
                throw ValidationException::withMessages([
                    'ckpn_workpaper_id' => 'CKPN adjustment cannot be approved after journal, export, or GL-to-GL output exists.',
                ]);
            }

            $item->forceFill([
                'adjusted_ckpn_rate' => $adjustment->requested_adjusted_ckpn_rate,
                'adjusted_ckpn_amount' => $adjustment->requested_adjusted_ckpn_amount,
                'adjustment_applied_at' => now(),
                'adjustment_applied_by' => $user->id,
                'adjustment_reason' => $adjustment->reason,
                'effective_ckpn_rate' => $adjustment->requested_adjusted_ckpn_rate,
                'effective_ckpn_amount' => $adjustment->requested_adjusted_ckpn_amount,
            ])->save();

            $adjustment->forceFill([
                'status' => CkpnAdjustment::STATUS_APPROVED,
                'approved_adjusted_ckpn_rate' => $adjustment->requested_adjusted_ckpn_rate,
                'approved_adjusted_ckpn_amount' => $adjustment->requested_adjusted_ckpn_amount,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            $this->recalculateTotals->handle($workpaper);

            return $adjustment->refresh();
        });
    }
}
