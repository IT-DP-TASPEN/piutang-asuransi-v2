<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'period',
    'branch_office_id',
    'status',
    'created_by',
    'approved_by',
    'approved_at',
    'total_receivable_amount',
    'total_calculated_ckpn_amount',
    'total_adjustment_delta',
    'total_effective_ckpn_amount',
    'total_ckpn_amount',
])]
class CkpnWorkpaper extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_LOCKED = 'locked';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_GENERATED => 'Generated',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_LOCKED => 'Locked',
        ];
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
     * @return HasMany<CkpnWorkpaperItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CkpnWorkpaperItem::class);
    }

    /**
     * @return HasMany<CkpnAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CkpnAdjustment::class);
    }

    /**
     * @return HasMany<CkpnJournal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(CkpnJournal::class);
    }

    /**
     * @return HasMany<GlToGlTransaction, $this>
     */
    public function glToGlTransactions(): HasMany
    {
        return $this->hasMany(GlToGlTransaction::class);
    }

    /**
     * @return MorphMany<GeneratedExport, $this>
     */
    public function generatedExports(): MorphMany
    {
        return $this->morphMany(GeneratedExport::class, 'exportable');
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
            'period' => 'date',
            'approved_at' => 'datetime',
            'total_receivable_amount' => 'decimal:2',
            'total_calculated_ckpn_amount' => 'decimal:2',
            'total_adjustment_delta' => 'decimal:2',
            'total_effective_ckpn_amount' => 'decimal:2',
            'total_ckpn_amount' => 'decimal:2',
        ];
    }
}
