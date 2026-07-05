<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'ckpn_workpaper_id',
    'ckpn_workpaper_item_id',
    'adjustment_type',
    'calculated_ckpn_rate',
    'calculated_ckpn_amount',
    'requested_adjusted_ckpn_rate',
    'requested_adjusted_ckpn_amount',
    'approved_adjusted_ckpn_rate',
    'approved_adjusted_ckpn_amount',
    'reason',
    'status',
    'requested_by',
    'approved_by',
    'approved_at',
])]
class CkpnAdjustment extends Model
{
    public const TYPE_OVERRIDE_FINAL_CKPN_AMOUNT = 'override_final_ckpn_amount';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

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
            self::STATUS_CANCELLED => 'Cancelled',
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
     * @return BelongsTo<CkpnWorkpaperItem, $this>
     */
    public function ckpnWorkpaperItem(): BelongsTo
    {
        return $this->belongsTo(CkpnWorkpaperItem::class);
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculated_ckpn_rate' => 'decimal:4',
            'calculated_ckpn_amount' => 'decimal:2',
            'requested_adjusted_ckpn_rate' => 'decimal:4',
            'requested_adjusted_ckpn_amount' => 'decimal:2',
            'approved_adjusted_ckpn_rate' => 'decimal:4',
            'approved_adjusted_ckpn_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }
}
