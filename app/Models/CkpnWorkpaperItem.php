<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ckpn_workpaper_id',
    'insurance_receivable_id',
    'branch_code',
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
    'final_ckpn_rate',
    'ckpn_amount',
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
            'final_ckpn_rate' => 'decimal:4',
            'ckpn_amount' => 'decimal:2',
            'snapshot' => 'array',
        ];
    }
}
