<?php

namespace App\Models;

use Database\Factories\LegacyReceivablePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legacy_receivable_id',
    'amount',
    'paid_at',
    'created_by',
])]
class LegacyReceivablePayment extends Model
{
    /** @use HasFactory<LegacyReceivablePaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<LegacyReceivable, $this>
     */
    public function legacyReceivable(): BelongsTo
    {
        return $this->belongsTo(LegacyReceivable::class);
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
}
