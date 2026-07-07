<?php

namespace App\Actions\ReceivablePayment;

use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordReceivablePaymentAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(InsuranceReceivable $receivable, array $data, User $user): ReceivablePayment
    {
        throw ValidationException::withMessages([
            'payment' => 'Receivable payments must be recorded through approved payment requests.',
        ]);

        return DB::transaction(function () use ($receivable, $data, $user): ReceivablePayment {
            $locked = $this->lockedReceivable($receivable);
            $this->validateReceivable($locked);

            $amount = $this->validatedAmount($data['amount'] ?? null);
            $remaining = BigDecimal::of($locked->remaining_receivable_amount)->toScale(2, RoundingMode::HalfUp);

            if (BigDecimal::of($amount)->isGreaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount cannot exceed current remaining receivable amount.',
                ]);
            }

            $payment = ReceivablePayment::query()->create([
                'insurance_receivable_id' => $locked->id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'created_by' => $user->id,
            ]);

            $locked->forceFill([
                'remaining_receivable_amount' => (string) $remaining->minus($amount)->toScale(2, RoundingMode::HalfUp),
            ])->save();

            return $payment->refresh();
        });
    }

    private function lockedReceivable(InsuranceReceivable $receivable): InsuranceReceivable
    {
        return $receivable->newQueryWithoutScopes()
            ->whereKey($receivable->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function validateReceivable(InsuranceReceivable $receivable): void
    {
        if ($receivable->trashed()) {
            throw ValidationException::withMessages([
                'receivable' => 'Payments cannot be recorded for deleted receivables.',
            ]);
        }

        if ($receivable->isLegacyOrigin()) {
            if (
                $receivable->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED
                && $receivable->system_status === InsuranceReceivable::SYSTEM_STATUS_LEGACY_IMPORTED
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'workflow_status' => 'Legacy receivable payments can only be recorded after import.',
            ]);
        }

        if (! in_array($receivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Insurance receivable payments can only be recorded after receivable formation.',
            ]);
        }
    }

    private function validatedAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount is required.',
            ]);
        }

        try {
            $decimal = BigDecimal::of((string) $amount);
        } catch (MathException) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be numeric.',
            ]);
        }

        if (! $decimal->isGreaterThan('0')) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than 0.',
            ]);
        }

        return (string) $decimal->toScale(2, RoundingMode::HalfUp);
    }
}
