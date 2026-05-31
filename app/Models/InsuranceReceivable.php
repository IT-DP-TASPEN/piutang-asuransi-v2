<?php

namespace App\Models;

use Database\Factories\InsuranceReceivableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'branch_office_id',
    'branch_code',
    'cif_no',
    'cif_no_alt',
    'loan_account_number',
    'alt_number',
    'customer_name',
    'date_of_death',
    'insurance_company_id',
    'claim_status_id',
    'credit_limit',
    'loan_outstanding',
    'collectability',
    'dpd',
    'product_id',
    'product_name',
    'start_period',
    'end_period',
    'receivable_formation_date',
    'receivable_amount',
    'workflow_status',
    'stage',
    'created_by',
    'submitted_at',
    'approved_at',
])]
class InsuranceReceivable extends Model
{
    /** @use HasFactory<InsuranceReceivableFactory> */
    use HasFactory, SoftDeletes;

    public const WORKFLOW_STATUS_DRAFT = 'draft';

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<InsuranceReceivableDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(InsuranceReceivableDocument::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_death' => 'date',
            'credit_limit' => 'decimal:2',
            'loan_outstanding' => 'decimal:2',
            'dpd' => 'integer',
            'start_period' => 'date',
            'end_period' => 'date',
            'receivable_formation_date' => 'date',
            'receivable_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
