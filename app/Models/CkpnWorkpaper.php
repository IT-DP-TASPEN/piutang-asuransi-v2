<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'period',
    'branch_office_id',
    'branch_scope_key',
    'status',
    'created_by',
    'approved_by',
    'approved_at',
    'last_error_message',
    'generated_at',
    'total_receivable_amount',
    'total_calculated_ckpn_amount',
    'total_adjustment_delta',
    'total_effective_ckpn_amount',
    'total_ckpn_amount',
])]
class CkpnWorkpaper extends Model
{
    public const BRANCH_SCOPE_CENTRAL = 'central';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATION_QUEUED = 'generation_queued';

    public const STATUS_GENERATION_PROCESSING = 'generation_processing';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_GENERATION_FAILED = 'generation_failed';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function submittableStatuses(): array
    {
        return [
            self::STATUS_GENERATED,
            self::STATUS_RETURNED,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_GENERATION_QUEUED => 'Generation queued',
            self::STATUS_GENERATION_PROCESSING => 'Generation processing',
            self::STATUS_GENERATED => 'Generated',
            self::STATUS_GENERATION_FAILED => 'Generation failed',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_LOCKED => 'Locked',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function normalizePeriod(mixed $period): Carbon
    {
        // period stores the selected CKPN cutoff date, not a monthly period.
        return Carbon::parse($period)->startOfDay();
    }

    public static function branchScopeKeyFor(?int $branchOfficeId): string
    {
        return $branchOfficeId === null
            ? self::BRANCH_SCOPE_CENTRAL
            : "branch:{$branchOfficeId}";
    }

    /**
     * @return array<string, mixed>
     */
    public static function queuedCreationAttributes(mixed $period, ?int $branchOfficeId, int $createdBy): array
    {
        return [
            'period' => self::normalizePeriod($period)->toDateString(),
            'branch_office_id' => $branchOfficeId,
            'branch_scope_key' => self::branchScopeKeyFor($branchOfficeId),
            'status' => self::STATUS_GENERATION_QUEUED,
            'created_by' => $createdBy,
            'last_error_message' => null,
            'generated_at' => null,
        ];
    }

    public function periodEnd(): Carbon
    {
        return self::normalizePeriod($this->period)->endOfDay();
    }

    public function branchScopeKey(): string
    {
        return self::branchScopeKeyFor($this->branch_office_id === null ? null : (int) $this->branch_office_id);
    }

    public function safeForGeneration(): bool
    {
        return in_array($this->status ?? self::STATUS_DRAFT, [
            self::STATUS_DRAFT,
            self::STATUS_GENERATION_QUEUED,
            self::STATUS_GENERATION_PROCESSING,
            self::STATUS_GENERATED,
            self::STATUS_GENERATION_FAILED,
            self::STATUS_RETURNED,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function jobGenerationStatuses(): array
    {
        return [
            self::STATUS_GENERATION_QUEUED,
            self::STATUS_GENERATION_FAILED,
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
            'generated_at' => 'datetime',
            'total_receivable_amount' => 'decimal:2',
            'total_calculated_ckpn_amount' => 'decimal:2',
            'total_adjustment_delta' => 'decimal:2',
            'total_effective_ckpn_amount' => 'decimal:2',
            'total_ckpn_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CkpnWorkpaper $workpaper): void {
            if ($workpaper->period !== null) {
                $workpaper->period = self::normalizePeriod($workpaper->period)->toDateString();
            }

            $workpaper->branch_scope_key = $workpaper->branchScopeKey();
        });
    }
}
