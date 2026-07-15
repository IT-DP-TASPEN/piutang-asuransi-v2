<?php

namespace App\Models;

use Database\Factories\ReceivablePaymentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'insurance_receivable_id',
    'approval_request_id',
    'gl_to_gl_transaction_id',
    'requested_by',
    'approved_by',
    'amount',
    'payment_source',
    'saving_account_number',
    'saving_account_snapshot',
    'status',
    'maker_notes',
    'approver_notes',
    'last_error_message',
    'submitted_at',
    'approved_at',
    'gl_executed_at',
    'receivable_payment_id',
])]
class ReceivablePaymentRequest extends Model
{
    /** @use HasFactory<ReceivablePaymentRequestFactory> */
    use HasFactory;

    public const PAYMENT_SOURCE_DEBTOR_SAVING = 'debtor_saving';

    public const PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02 = 'current_account_mandiri_02';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_GL_FAILED = 'gl_failed';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const STATUS_PAYMENT_RECORDED = 'payment_recorded';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function paymentSourceOptions(): array
    {
        return [
            self::PAYMENT_SOURCE_DEBTOR_SAVING => "Debit from Debtor's Saving",
            self::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02 => 'Debit from Current Account Mandiri 02',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_VALIDATION_FAILED => 'Validation failed',
            self::STATUS_GL_FAILED => 'GL failed',
            self::STATUS_RECONCILIATION_REQUIRED => 'Reconciliation required',
            self::STATUS_PAYMENT_RECORDED => 'Payment recorded',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_GL_FAILED,
        ], true);
    }

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * @return BelongsTo<GlToGlTransaction, $this>
     */
    public function glToGlTransaction(): BelongsTo
    {
        return $this->belongsTo(GlToGlTransaction::class);
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'saving_account_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'gl_executed_at' => 'datetime',
        ];
    }
}
