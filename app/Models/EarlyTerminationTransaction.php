<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'trx_reference',
    'request_payload',
    'response_payload',
    'response_code',
    'response_description',
    'transaction_id',
    'journal_id',
    'core_trx_reference',
    'alternate_number',
    'status',
    'executed_by',
    'executed_at',
])]
class EarlyTerminationTransaction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

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
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
