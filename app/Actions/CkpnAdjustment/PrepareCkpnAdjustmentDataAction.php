<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaperItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class PrepareCkpnAdjustmentDataAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data, User $user): array
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'CKPN adjustment reason is required.',
            ]);
        }

        $item = isset($data['ckpn_workpaper_item_id'])
            ? CkpnWorkpaperItem::query()->find($data['ckpn_workpaper_item_id'])
            : null;

        if (! $item instanceof CkpnWorkpaperItem) {
            throw ValidationException::withMessages([
                'ckpn_workpaper_item_id' => 'CKPN workpaper item is required.',
            ]);
        }

        if (blank($data['requested_adjusted_ckpn_amount'] ?? null)) {
            throw ValidationException::withMessages([
                'requested_adjusted_ckpn_amount' => 'Requested adjusted CKPN amount is required.',
            ]);
        }

        $requestedAmount = (string) BigDecimal::of($data['requested_adjusted_ckpn_amount'])->toScale(2, RoundingMode::HALF_UP);
        $requestedRate = $data['requested_adjusted_ckpn_rate'] ?? null;

        if (BigDecimal::of($requestedAmount)->isLessThan('0')) {
            throw ValidationException::withMessages([
                'requested_adjusted_ckpn_amount' => 'Requested adjusted CKPN amount cannot be negative.',
            ]);
        }

        if ($requestedRate === null || $requestedRate === '') {
            if (BigDecimal::of($item->receivable_amount)->isLessThanOrEqualTo('0')) {
                throw ValidationException::withMessages([
                    'receivable_amount' => 'Receivable amount must be greater than zero to calculate adjustment rate.',
                ]);
            }

            $requestedRate = (string) BigDecimal::of($requestedAmount)
                ->multipliedBy('100')
                ->dividedBy($item->receivable_amount, 4, RoundingMode::HALF_UP);
        }

        return [
            'adjustment_type' => $data['adjustment_type'] ?? CkpnAdjustment::TYPE_OVERRIDE_FINAL_CKPN_AMOUNT,
            'receivable_type' => $data['receivable_type'] ?? $item->receivable_type,
            'receivable_id' => $data['receivable_id'] ?? $item->receivable_id,
            'ckpn_workpaper_id' => $data['ckpn_workpaper_id'] ?? $item->ckpn_workpaper_id,
            'ckpn_workpaper_item_id' => $item->id,
            'calculated_ckpn_rate' => $item->calculated_ckpn_rate,
            'calculated_ckpn_amount' => $item->calculated_ckpn_amount,
            'requested_adjusted_ckpn_rate' => (string) BigDecimal::of($requestedRate)->toScale(4, RoundingMode::HALF_UP),
            'requested_adjusted_ckpn_amount' => $requestedAmount,
            'approved_adjusted_ckpn_rate' => null,
            'approved_adjusted_ckpn_amount' => null,
            'reason' => $data['reason'],
            'status' => $data['status'] ?? CkpnAdjustment::STATUS_DRAFT,
            'requested_by' => $data['requested_by'] ?? $user->id,
        ];
    }
}
