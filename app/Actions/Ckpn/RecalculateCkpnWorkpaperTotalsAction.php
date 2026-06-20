<?php

namespace App\Actions\Ckpn;

use App\Models\CkpnWorkpaper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class RecalculateCkpnWorkpaperTotalsAction
{
    public function handle(CkpnWorkpaper $workpaper): CkpnWorkpaper
    {
        $items = $workpaper->items()->get([
            'calculated_ckpn_amount',
            'effective_ckpn_amount',
        ]);

        $totalCalculated = BigDecimal::of('0');
        $totalEffective = BigDecimal::of('0');

        foreach ($items as $item) {
            $totalCalculated = $totalCalculated->plus($item->calculated_ckpn_amount);
            $totalEffective = $totalEffective->plus($item->effective_ckpn_amount);
        }

        $totalCalculated = $totalCalculated->toScale(2, RoundingMode::HalfUp);
        $totalEffective = $totalEffective->toScale(2, RoundingMode::HalfUp);
        $delta = $totalEffective->minus($totalCalculated)->toScale(2, RoundingMode::HalfUp);

        $workpaper->forceFill([
            'total_calculated_ckpn_amount' => (string) $totalCalculated,
            'total_adjustment_delta' => (string) $delta,
            'total_effective_ckpn_amount' => (string) $totalEffective,
            'total_ckpn_amount' => (string) $totalEffective,
        ])->save();

        return $workpaper->refresh();
    }
}
