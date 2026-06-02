<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'ckpn_workpaper_id',
    'branch_office_id',
    'journal_date',
    'total_amount',
    'debit_account',
    'credit_account',
    'debit_narrative',
    'credit_narrative',
    'description',
    'status',
    'created_by',
    'approved_by',
    'approved_at',
])]
class CkpnJournal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_GL_TO_GL_QUEUED = 'gl_to_gl_queued';

    public const STATUS_GL_TO_GL_PROCESSING = 'gl_to_gl_processing';

    public const STATUS_GL_TO_GL_EXECUTED = 'gl_to_gl_executed';

    public const STATUS_GL_TO_GL_FAILED = 'gl_to_gl_failed';

    /**
     * @return list<string>
     */
    public static function editableStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_RETURNED,
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::editableStatuses(), true);
    }

    public function derivedTotalAmount(): ?string
    {
        return $this->ckpnWorkpaper?->total_effective_ckpn_amount;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_GL_TO_GL_QUEUED => 'GL-to-GL queued',
            self::STATUS_GL_TO_GL_PROCESSING => 'GL-to-GL processing',
            self::STATUS_GL_TO_GL_EXECUTED => 'GL-to-GL executed',
            self::STATUS_GL_TO_GL_FAILED => 'GL-to-GL failed',
        ];
    }

    /**
     * @return BelongsTo<CkpnWorkpaper, $this>
     */
    public function ckpnWorkpaper(): BelongsTo
    {
        return $this->belongsTo(CkpnWorkpaper::class);
    }

    /**
     * @return BelongsTo<BranchOffice, $this>
     */
    public function branchOffice(): BelongsTo
    {
        return $this->belongsTo(BranchOffice::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<GlToGlTransaction, $this>
     */
    public function glToGlTransactions(): HasMany
    {
        return $this->hasMany(GlToGlTransaction::class);
    }

    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
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
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }
}
