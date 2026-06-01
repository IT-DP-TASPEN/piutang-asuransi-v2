<?php

namespace App\Actions\LegacyReceivable;

use App\Models\LegacyReceivable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecalculateLegacyReceivableRemainingAmountAction
{
    public function handle(LegacyReceivable $legacyReceivable): LegacyReceivable
    {
        return DB::transaction(function () use ($legacyReceivable): LegacyReceivable {
            $locked = LegacyReceivable::query()
                ->whereKey($legacyReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $paidAmount = BigDecimal::of((string) $locked->payments()->sum('amount'))->toScale(2, RoundingMode::HALF_UP);
            $remainingAmount = BigDecimal::of($locked->original_receivable_amount)
                ->minus($paidAmount)
                ->toScale(2, RoundingMode::HALF_UP);

            if ($remainingAmount->isLessThan('0')) {
                throw ValidationException::withMessages([
                    'amount' => 'Total payments cannot exceed original receivable amount.',
                ]);
            }

            $locked->forceFill([
                'remaining_receivable_amount' => (string) $remainingAmount,
            ])->save();

            return $locked->refresh();
        });
    }
}
