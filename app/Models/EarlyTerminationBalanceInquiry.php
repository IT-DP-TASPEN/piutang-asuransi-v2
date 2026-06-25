<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'insurance_receivable_id',
    'api_integration_log_id',
    'saving_account_number',
    'loan_outstanding_amount',
    'available_balance',
    'required_top_up_amount',
    'response_code',
    'response_description',
    'status',
    'error_message',
    'requested_by',
    'requested_at',
    'completed_at',
])]
class EarlyTerminationBalanceInquiry extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_PARSE_FAILED = 'parse_failed';

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<ApiIntegrationLog, $this>
     */
    public function apiIntegrationLog(): BelongsTo
    {
        return $this->belongsTo(ApiIntegrationLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasOne<GlToGlTransaction, $this>
     */
    public function glToGlTransaction(): HasOne
    {
        return $this->hasOne(GlToGlTransaction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loan_outstanding_amount' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'required_top_up_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
