<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'insurance_receivable_id',
    'api_integration_log_id',
    'balance_api_integration_log_id',
    'post_repayment_inquiry_api_integration_log_id',
    'reference_number',
    'account_number',
    'alt_number',
    'branch_code',
    'saving_account_number',
    'installment_amount',
    'loan_outstanding_before',
    'loan_outstanding_after',
    'next_due_date',
    'date_of_death',
    'status',
    'response_code',
    'response_description',
    'request_payload',
    'response_payload',
    'last_error_message',
    'resolution_outcome',
    'resolution_payload',
    'resolution_notes',
    'executed_by',
    'executed_at',
    'resolved_by',
    'resolved_at',
])]
class InsuranceReceivableInstallmentRepayment extends Model
{
    public const STATUS_REQUIRED = 'required';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN_TIMEOUT = 'unknown_timeout';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const STATUS_NO_LONGER_REQUIRED = 'no_longer_required';

    public const STATUS_VERIFICATION_FAILED_AFTER_EXECUTION = 'verification_failed_after_execution';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_RESOLVED_MANUALLY = 'resolved_manually';

    public const RESOLUTION_OUTCOME_POSTED = 'resolved_as_posted';

    public const RESOLUTION_OUTCOME_NOT_POSTED = 'resolved_as_not_posted';

    public const RESOLUTION_OUTCOME_STILL_UNKNOWN = 'still_unknown';

    public static function retryableStatuses(): array
    {
        return [
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_FAILED,
        ];
    }

    public static function resolvableStatuses(): array
    {
        return [
            self::STATUS_REQUIRED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_FAILED,
            self::STATUS_UNKNOWN_TIMEOUT,
            self::STATUS_RECONCILIATION_REQUIRED,
            self::STATUS_VERIFICATION_FAILED_AFTER_EXECUTION,
        ];
    }

    public function canRetry(): bool
    {
        return in_array($this->status, self::retryableStatuses(), true)
            && $this->status !== self::STATUS_NO_LONGER_REQUIRED;
    }

    public function canResolve(): bool
    {
        return in_array($this->status, self::resolvableStatuses(), true);
    }

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
     * @return BelongsTo<ApiIntegrationLog, $this>
     */
    public function balanceApiIntegrationLog(): BelongsTo
    {
        return $this->belongsTo(ApiIntegrationLog::class, 'balance_api_integration_log_id');
    }

    /**
     * @return BelongsTo<ApiIntegrationLog, $this>
     */
    public function postRepaymentInquiryApiIntegrationLog(): BelongsTo
    {
        return $this->belongsTo(ApiIntegrationLog::class, 'post_repayment_inquiry_api_integration_log_id');
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
     * @return HasMany<InsuranceReceivableInstallmentRepaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(InsuranceReceivableInstallmentRepaymentAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'loan_outstanding_before' => 'decimal:2',
            'loan_outstanding_after' => 'decimal:2',
            'next_due_date' => 'date',
            'date_of_death' => 'date',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'resolution_payload' => 'array',
            'executed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
