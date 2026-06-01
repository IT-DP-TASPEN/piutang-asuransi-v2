<?php

namespace App\Actions\LegacyReceivable;

use App\Models\LegacyReceivable;
use App\Models\LegacyReceivablePayment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordLegacyReceivablePaymentAction
{
    public function __construct(
        private readonly RecalculateLegacyReceivableRemainingAmountAction $recalculateRemainingAmountAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(LegacyReceivable $legacyReceivable, array $data, ?User $user = null): LegacyReceivablePayment
    {
        return DB::transaction(function () use ($legacyReceivable, $data, $user): LegacyReceivablePayment {
            $locked = LegacyReceivable::query()
                ->whereKey($legacyReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $amount = $this->validatedAmount($data['amount'] ?? null);

            if (BigDecimal::of($amount)->isGreaterThan($locked->remaining_receivable_amount)) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount cannot exceed current remaining receivable amount.',
                ]);
            }

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'created_by' => $data['created_by'] ?? $user?->id,
            ]);

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
