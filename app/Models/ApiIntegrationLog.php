<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'service_name',
    'endpoint',
    'method',
    'request_headers',
    'request_body',
    'response_status',
    'response_body',
    'response_code',
    'response_description',
    'is_success',
    'error_message',
    'related_type',
    'related_id',
    'requested_by',
    'requested_at',
])]
class ApiIntegrationLog extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function earlyTerminationBalanceInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class);
    }

    /**
     * @return HasOne<InsuranceReceivableInstallmentRepayment, $this>
     */
    public function installmentRepayment(): HasOne
    {
        return $this->hasOne(InsuranceReceivableInstallmentRepayment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_body' => 'array',
            'response_status' => 'integer',
            'is_success' => 'boolean',
            'requested_at' => 'datetime',
        ];
    }
}
