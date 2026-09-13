<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purpose',
    'ckpn_journal_id',
    'ckpn_workpaper_id',
    'insurance_receivable_id',
    'early_termination_balance_inquiry_id',
    'receivable_payment_request_id',
    'receivable_payment_id',
    'attempt_no',
    'reference_number',
    'receipt_number',
    'idempotency_key',
    'request_payload',
    'response_payload',
    'response_code',
    'response_description',
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
class GlToGlTransaction extends Model
{
    public const PURPOSE_CKPN_JOURNAL = 'ckpn_journal';

    public const PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP = 'early_termination_repayment_top_up';

    public const PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP = 'early_termination_flat_spread_top_up';

    public const PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP = 'early_termination_contract_top_up';

    public const PURPOSE_RECEIVABLE_PAYMENT = 'receivable_payment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN_TIMEOUT = 'unknown_timeout';

    public const RESOLUTION_STATUS_NO_LONGER_REQUIRED = 'no_longer_required';

    public const RESOLUTION_STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const RESOLUTION_STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_STATUS_RESOLVED_MANUALLY = 'resolved_manually';

    public const RESOLUTION_OUTCOME_POSTED = 'resolved_as_posted';

    public const RESOLUTION_OUTCOME_NOT_POSTED = 'resolved_as_not_posted';

    public const RESOLUTION_OUTCOME_STILL_UNKNOWN = 'still_unknown';

    /**
     * @return list<string>
     */
    public static function earlyTerminationTopUpPurposes(): array
    {
        return [
            self::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP,
            self::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            self::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
        ];
    }

    public function isSatisfied(): bool
    {
        return $this->status === self::STATUS_SUCCESS
            || $this->resolution_status === self::RESOLUTION_STATUS_NO_LONGER_REQUIRED
            || ($this->resolution_outcome === self::RESOLUTION_OUTCOME_POSTED
                && in_array($this->resolution_status, [
                    self::RESOLUTION_STATUS_RESOLVED,
                    self::RESOLUTION_STATUS_RESOLVED_MANUALLY,
                ], true));
    }

    public function canRetry(): bool
    {
        if ($this->resolution_outcome === self::RESOLUTION_OUTCOME_NOT_POSTED) {
            return in_array($this->resolution_status, [
                self::RESOLUTION_STATUS_RESOLVED,
                self::RESOLUTION_STATUS_RESOLVED_MANUALLY,
            ], true);
        }

        return $this->status === self::STATUS_FAILED && $this->resolution_status === null;
    }

    /**
     * @return BelongsTo<CkpnJournal, $this>
     */
    public function ckpnJournal(): BelongsTo
    {
        return $this->belongsTo(CkpnJournal::class);
    }

    /**
     * @return BelongsTo<CkpnWorkpaper, $this>
     */
    public function ckpnWorkpaper(): BelongsTo
    {
        return $this->belongsTo(CkpnWorkpaper::class);
    }

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<EarlyTerminationBalanceInquiry, $this>
     */
    public function earlyTerminationBalanceInquiry(): BelongsTo
    {
        return $this->belongsTo(EarlyTerminationBalanceInquiry::class);
    }

    /**
     * @return BelongsTo<ReceivablePaymentRequest, $this>
     */
    public function receivablePaymentRequest(): BelongsTo
    {
        return $this->belongsTo(ReceivablePaymentRequest::class);
    }

    /**
     * @return BelongsTo<ReceivablePayment, $this>
     */
    public function receivablePayment(): BelongsTo
    {
        return $this->belongsTo(ReceivablePayment::class);
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
