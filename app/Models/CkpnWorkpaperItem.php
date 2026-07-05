<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ckpn_workpaper_id',
    'insurance_receivable_id',
    'origin_type',
    'branch_code',
    'branch_name',
    'cif_no',
    'loan_account_number',
    'customer_name',
    'insurance_company_name',
    'claim_status_name',
    'receivable_formation_date',
    'receivable_amount',
    'age_days',
    'age_bucket_name',
    'insurance_company_weight',
    'age_weight',
    'claim_status_weight',
    'calculated_ckpn_rate',
    'calculated_ckpn_amount',
    'adjusted_ckpn_rate',
    'adjusted_ckpn_amount',
    'adjustment_applied_at',
    'adjustment_applied_by',
    'adjustment_reason',
    'effective_ckpn_rate',
    'effective_ckpn_amount',
    'calculation_rule_code',
    'calculation_explanation',
    'snapshot',
])]
class CkpnWorkpaperItem extends Model
{
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
     * @return HasMany<CkpnAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CkpnAdjustment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adjustmentApplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjustment_applied_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receivable_formation_date' => 'date',
            'receivable_amount' => 'decimal:2',
            'age_days' => 'integer',
            'insurance_company_weight' => 'decimal:4',
            'age_weight' => 'decimal:4',
            'claim_status_weight' => 'decimal:4',
            'calculated_ckpn_rate' => 'decimal:4',
            'calculated_ckpn_amount' => 'decimal:2',
            'adjusted_ckpn_rate' => 'decimal:4',
            'adjusted_ckpn_amount' => 'decimal:2',
            'adjustment_applied_at' => 'datetime',
            'effective_ckpn_rate' => 'decimal:4',
            'effective_ckpn_amount' => 'decimal:2',
            'snapshot' => 'array',
        ];
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->origin_type) {
            InsuranceReceivable::ORIGIN_TYPE_LEGACY => 'Legacy',
            InsuranceReceivable::ORIGIN_TYPE_WORKFLOW => 'Insurance Receivable',
            default => (string) $this->origin_type,
        };
    }
}
