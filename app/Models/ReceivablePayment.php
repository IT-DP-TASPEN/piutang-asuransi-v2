<?php

namespace App\Models;

use Database\Factories\ReceivablePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'legacy_receivable_id',
    'insurance_receivable_id',
    'amount',
    'paid_at',
    'created_by',
])]
class ReceivablePayment extends Model
{
    /** @use HasFactory<ReceivablePaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<LegacyReceivable, $this>
     */
    public function legacyReceivable(): BelongsTo
    {
        return $this->belongsTo(LegacyReceivable::class);
    }

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ReceivablePayment $payment): void {
            $hasLegacyTarget = $payment->legacy_receivable_id !== null;
            $hasInsuranceTarget = $payment->insurance_receivable_id !== null;

            if ($hasLegacyTarget === $hasInsuranceTarget) {
                throw ValidationException::withMessages([
                    'receivable' => 'Payment must belong to exactly one receivable.',
                ]);
            }
        });

        static::updating(function (): void {
            throw ValidationException::withMessages([
                'payment' => 'Recorded payments cannot be updated.',
            ]);
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'payment' => 'Recorded payments cannot be deleted.',
            ]);
        });
    }
}
