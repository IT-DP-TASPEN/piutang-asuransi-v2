<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_installment_repayment_id',
    'api_integration_log_id',
    'attempt_no',
    'reference_number',
    'request_payload',
    'response_payload',
    'response_code',
    'response_description',
    'status',
    'error_message',
    'executed_by',
    'executed_at',
])]
class InsuranceReceivableInstallmentRepaymentAttempt extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN_TIMEOUT = 'unknown_timeout';

    /**
     * @return BelongsTo<InsuranceReceivableInstallmentRepayment, $this>
     */
    public function repayment(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivableInstallmentRepayment::class, 'insurance_receivable_installment_repayment_id');
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
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
