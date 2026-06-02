<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'insurance_receivable_id',
    'journal_date',
    'amount',
    'debit_account',
    'credit_account',
    'description',
    'notes',
    'status',
    'created_by',
    'submitted_by',
    'submitted_at',
    'approved_by',
    'approved_at',
    'returned_by',
    'returned_at',
    'rejected_by',
    'rejected_at',
    'approval_request_id',
    'snapshot',
])]
class ReceivableFormationJournal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    private const IMMUTABLE_SNAPSHOT_COLUMNS = [
        'journal_date',
        'amount',
        'debit_account',
        'credit_account',
        'description',
        'notes',
        'submitted_by',
        'submitted_at',
        'snapshot',
    ];

    /**
     * @return array<string, mixed>
     */
    public function frozenSnapshot(): array
    {
        return $this->snapshot ?: [
            'journal_date' => $this->journal_date?->toDateString(),
            'amount' => $this->amount,
            'debit_account' => $this->debit_account,
            'credit_account' => $this->credit_account,
            'description' => $this->description,
            'notes' => $this->notes,
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'rejected_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ReceivableFormationJournal $journal): void {
            if (! $journal->exists || $journal->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }

            foreach (self::IMMUTABLE_SNAPSHOT_COLUMNS as $column) {
                if ($journal->isDirty($column)) {
                    throw new LogicException('Submitted accounting validation snapshots cannot be mutated.');
                }
            }
        });
    }
}
