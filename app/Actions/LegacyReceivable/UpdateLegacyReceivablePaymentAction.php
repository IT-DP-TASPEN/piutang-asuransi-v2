<?php

namespace App\Actions\LegacyReceivable;

use App\Models\LegacyReceivable;
use App\Models\LegacyReceivablePayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLegacyReceivablePaymentAction
{
    public function __construct(
        private readonly RecalculateLegacyReceivableRemainingAmountAction $recalculateRemainingAmountAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(LegacyReceivablePayment $payment, array $data): LegacyReceivablePayment
    {
        return DB::transaction(function () use ($payment, $data): LegacyReceivablePayment {
            $locked = LegacyReceivable::query()
                ->whereKey($payment->legacy_receivable_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = $this->validatedAmount($data['amount'] ?? null);
            $otherPaid = BigDecimal::of((string) $locked->payments()
                ->whereKeyNot($payment->getKey())
                ->sum('amount'))
                ->toScale(2, RoundingMode::HalfUp);
            $available = BigDecimal::of($locked->original_receivable_amount)->minus($otherPaid);

            if (BigDecimal::of($amount)->isGreaterThan($available)) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount cannot exceed remaining receivable after excluding this payment.',
                ]);
            }

            $payment->forceFill([
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? $payment->paid_at,
            ])->save();

            $this->recalculateRemainingAmountAction->handle($locked);

            return $payment->refresh();
        });
    }

    private function validatedAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount is required.',
            ]);
        }

        $decimal = BigDecimal::of((string) $amount);

        if (! $decimal->isGreaterThan('0')) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than 0.',
            ]);
        }

        return (string) $decimal;
    }
}
