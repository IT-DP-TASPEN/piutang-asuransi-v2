<?php

namespace App\Models;

use Database\Factories\LegacyReceivableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'cif',
    'customer_name',
    'loan_account_number',
    'loan_alt_account_number',
    'branch_office_id',
    'loan_outstanding',
    'insurance_company_id',
    'date_of_death',
    'receivable_formation_date',
    'original_receivable_amount',
    'remaining_receivable_amount',
    'claim_status_id',
    'created_by',
])]
class LegacyReceivable extends Model
{
    /** @use HasFactory<LegacyReceivableFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<BranchOffice, $this>
     */
    public function branchOffice(): BelongsTo
    {
        return $this->belongsTo(BranchOffice::class);
    }

    /**
     * @return BelongsTo<InsuranceCompany, $this>
     */
    public function insuranceCompany(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class);
    }

    /**
     * @return BelongsTo<ClaimStatus, $this>
     */
    public function claimStatus(): BelongsTo
    {
        return $this->belongsTo(ClaimStatus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<LegacyReceivablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(LegacyReceivablePayment::class);
    }

    /**
     * @return MorphMany<CkpnWorkpaperItem, $this>
     */
    public function ckpnWorkpaperItems(): MorphMany
    {
        return $this->morphMany(CkpnWorkpaperItem::class, 'receivable');
    }

    /**
     * @return MorphMany<CkpnAdjustment, $this>
     */
    public function ckpnAdjustments(): MorphMany
    {
        return $this->morphMany(CkpnAdjustment::class, 'receivable');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loan_outstanding' => 'decimal:2',
            'date_of_death' => 'date',
            'receivable_formation_date' => 'date',
            'original_receivable_amount' => 'decimal:2',
            'remaining_receivable_amount' => 'decimal:2',
        ];
    }
}
