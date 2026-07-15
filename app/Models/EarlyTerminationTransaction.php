<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'operation_key',
    'attempt_no',
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
    'resolution_status',
    'resolution_outcome',
    'resolution_reason',
    'resolution_payload',
    'resolution_notes',
    'resolved_by',
    'resolved_at',
    'executed_by',
    'executed_at',
])]
class EarlyTerminationTransaction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN_TIMEOUT = 'unknown_timeout';

    public const RESOLUTION_STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const RESOLUTION_STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_OUTCOME_POSTED = 'resolved_as_posted';

    public const RESOLUTION_OUTCOME_NOT_POSTED = 'resolved_as_not_posted';

    public const RESOLUTION_OUTCOME_STILL_UNKNOWN = 'still_unknown';

    public function canRetry(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        return $this->resolution_status === null
            || $this->resolution_outcome === self::RESOLUTION_OUTCOME_NOT_POSTED;
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
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
            'resolution_payload' => 'array',
            'executed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
