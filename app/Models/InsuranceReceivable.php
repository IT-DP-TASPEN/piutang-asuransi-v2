<?php

namespace App\Models;

use Database\Factories\InsuranceReceivableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'origin_type',
    'branch_office_id',
    'branch_code',
    'cif_no',
    'cif_no_alt',
    'loan_account_number',
    'alt_number',
    'customer_name',
    'date_of_death',
    'death_document_condition',
    'insurance_company_id',
    'claim_status_id',
    'credit_limit',
    'loan_outstanding',
    'contract_outstanding_amount',
    'contract_outstanding_requested_as_of',
    'contract_outstanding_as_of',
    'contract_outstanding_product_code',
    'contract_outstanding_trx_type',
    'contract_outstanding_api_log_id',
    'collectability',
    'dpd',
    'product_id',
    'product_name',
    'saving_account_for_loan_repayment',
    'start_period',
    'end_period',
    'receivable_formation_date',
    'receivable_amount',
    'remaining_receivable_amount',
    'workflow_status',
    'system_status',
    'last_error_message',
    'stage',
    'created_by',
    'submitted_at',
    'approved_at',
    'inquiry_completed_at',
    'early_termination_executed_at',
    'early_termination_resolved_at',
])]
class InsuranceReceivable extends Model
{
    /** @use HasFactory<InsuranceReceivableFactory> */
    use HasFactory, SoftDeletes;

    public const ORIGIN_TYPE_LEGACY = 'legacy';

    public const ORIGIN_TYPE_WORKFLOW = 'workflow';

    public const WORKFLOW_STATUS_DRAFT = 'draft';

    public const WORKFLOW_STATUS_SUBMITTED = 'submitted';

    public const WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING = 'collectability_confirmation_pending';

    public const WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER = 'returned_to_branch_maker';

    public const WORKFLOW_STATUS_REJECTED = 'rejected';

    public const WORKFLOW_STATUS_CANCELLED = 'cancelled';

    public const WORKFLOW_STATUS_ACCOUNTING_VALIDATION = 'accounting_validation';

    public const WORKFLOW_STATUS_RECEIVABLE_FORMED = 'receivable_formed';

    public const WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING = 'manual_early_termination_pending';

    public const WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED = 'manual_early_termination_submitted';

    public const WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED = 'early_termination_executed';

    public const WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED = 'early_termination_resolved';

    public const SYSTEM_STATUS_INQUIRY_QUEUED = 'inquiry_queued';

    public const SYSTEM_STATUS_INQUIRY_PROCESSING = 'inquiry_processing';

    public const SYSTEM_STATUS_INQUIRY_COMPLETED = 'inquiry_completed';

    public const SYSTEM_STATUS_INQUIRY_FAILED = 'inquiry_failed';

    public const SYSTEM_STATUS_REINQUIRY_REQUIRED = 'reinquiry_required';

    public const SYSTEM_STATUS_BRANCH_VALIDATION_FAILED = 'branch_validation_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_QUEUED = 'early_termination_queued';

    public const SYSTEM_STATUS_EARLY_TERMINATION_PENDING = 'early_termination_pending';

    public const SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED = 'early_termination_manual_execution_required';

    public const SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED = 'early_termination_top_up_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED = 'early_termination_top_up_executed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING = 'early_termination_processing';

    public const SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED = 'early_termination_executed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_FAILED = 'early_termination_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED = 'early_termination_resolved';

    public const SYSTEM_STATUS_LEGACY_IMPORTED = 'legacy_imported';

    public const DOCUMENT_DISK = 'local';

    public const DEATH_DOCUMENT_CONDITION_HOSPITAL = 'hospital';

    public const DEATH_DOCUMENT_CONDITION_ACCIDENT = 'accident';

    public const DEATH_DOCUMENT_CONDITION_OVERSEAS = 'overseas';

    public const DEATH_DOCUMENT_CONDITION_HOME = 'home';

    public const DEATH_DOCUMENT_CONDITION_CIVIL_REGISTRY = 'civil_registry';

    /**
     * @return array<string, string>
     */
    public static function deathDocumentConditionOptions(): array
    {
        return [
            self::DEATH_DOCUMENT_CONDITION_HOSPITAL => 'Meninggal di rumah sakit',
            self::DEATH_DOCUMENT_CONDITION_ACCIDENT => 'Meninggal karena kecelakaan',
            self::DEATH_DOCUMENT_CONDITION_OVERSEAS => 'Meninggal di luar negeri',
            self::DEATH_DOCUMENT_CONDITION_HOME => 'Meninggal di rumah',
            self::DEATH_DOCUMENT_CONDITION_CIVIL_REGISTRY => 'Akta kematian Dukcapil',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function workflowStatusOptions(): array
    {
        return [
            self::WORKFLOW_STATUS_DRAFT => 'Draft',
            self::WORKFLOW_STATUS_SUBMITTED => 'Submitted',
            self::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING => 'Collectability confirmation pending',
            self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER => 'Returned to branch maker',
            self::WORKFLOW_STATUS_REJECTED => 'Rejected',
            self::WORKFLOW_STATUS_CANCELLED => 'Cancelled',
            self::WORKFLOW_STATUS_ACCOUNTING_VALIDATION => 'Accounting validation',
            self::WORKFLOW_STATUS_RECEIVABLE_FORMED => 'Receivable formed',
            self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING => 'Manual Early Termination Pending',
            self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED => 'Manual Early Termination Submitted',
            self::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED => 'Early termination executed',
            self::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED => 'Early termination resolved',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function originTypeOptions(): array
    {
        return [
            self::ORIGIN_TYPE_LEGACY => 'Legacy',
            self::ORIGIN_TYPE_WORKFLOW => 'Workflow',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function systemStatusOptions(): array
    {
        return [
            self::SYSTEM_STATUS_INQUIRY_QUEUED => 'Inquiry queued',
            self::SYSTEM_STATUS_INQUIRY_PROCESSING => 'Inquiry processing',
            self::SYSTEM_STATUS_INQUIRY_COMPLETED => 'Inquiry completed',
            self::SYSTEM_STATUS_INQUIRY_FAILED => 'Inquiry failed',
            self::SYSTEM_STATUS_REINQUIRY_REQUIRED => 'Re-inquiry required',
            self::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED => 'Branch validation failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED => 'Early termination queued',
            self::SYSTEM_STATUS_EARLY_TERMINATION_PENDING => 'Early termination pending',
            self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED => 'Manual Early Termination Required',
            self::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED => 'Early termination top up failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED => 'Early termination top up executed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING => 'Early termination processing',
            self::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED => 'Early termination executed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_FAILED => 'Early termination failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED => 'Early termination resolved',
            self::SYSTEM_STATUS_LEGACY_IMPORTED => 'Legacy imported',
        ];
    }

    public function isLegacyOrigin(): bool
    {
        return $this->origin_type === self::ORIGIN_TYPE_LEGACY;
    }

    public function isWorkflowOrigin(): bool
    {
        return $this->origin_type === null || $this->origin_type === self::ORIGIN_TYPE_WORKFLOW;
    }

    public function originLabel(): string
    {
        return self::originTypeOptions()[$this->origin_type] ?? (string) $this->origin_type;
    }

    public function isTerminal(): bool
    {
        return in_array($this->workflow_status, [
            self::WORKFLOW_STATUS_REJECTED,
            self::WORKFLOW_STATUS_CANCELLED,
        ], true);
    }

    public function isEditable(): bool
    {
        return $this->isWorkflowOrigin()
            && in_array($this->workflow_status, [
                self::WORKFLOW_STATUS_DRAFT,
                self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true) && ! $this->isTerminal();
    }

    public function canRetryInquiry(): bool
    {
        return $this->isWorkflowOrigin()
            && ! $this->isTerminal()
            && in_array($this->workflow_status, [
                self::WORKFLOW_STATUS_DRAFT,
                self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)
            && in_array($this->system_status, [
                self::SYSTEM_STATUS_INQUIRY_FAILED,
                self::SYSTEM_STATUS_REINQUIRY_REQUIRED,
            ], true);
    }

    public function canCancelFailedInquiry(): bool
    {
        return $this->isWorkflowOrigin()
            && ! $this->isTerminal()
            && in_array($this->workflow_status, [
                self::WORKFLOW_STATUS_DRAFT,
                self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)
            && in_array($this->system_status, [
                self::SYSTEM_STATUS_INQUIRY_FAILED,
                self::SYSTEM_STATUS_REINQUIRY_REQUIRED,
            ], true);
    }

    public function canResolveEarlyTermination(): bool
    {
        if ($this->isLegacyOrigin()) {
            return false;
        }

        if ($this->isTerminal()) {
            return false;
        }

        if ($this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_FAILED) {
            return true;
        }

        return $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED
            && $this->workflow_status !== self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING;
    }

    public function canReconcileEarlyTerminationTopUp(): bool
    {
        if ($this->isLegacyOrigin() || $this->isTerminal()) {
            return false;
        }

        return $this->glToGlTransactions()
            ->whereIn('purpose', [
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            ])
            ->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
            ->exists();
    }

    public function canRetryInstallmentRepayment(): bool
    {
        if ($this->isLegacyOrigin() || $this->isTerminal()) {
            return false;
        }

        $repayment = $this->installmentRepayment;

        return $repayment instanceof InsuranceReceivableInstallmentRepayment
            && $repayment->canRetry()
            && $this->workflow_status === self::WORKFLOW_STATUS_ACCOUNTING_VALIDATION;
    }

    public function canResolveInstallmentRepayment(): bool
    {
        if ($this->isLegacyOrigin() || $this->isTerminal()) {
            return false;
        }

        $repayment = $this->installmentRepayment;

        return $repayment instanceof InsuranceReceivableInstallmentRepayment
            && $repayment->canResolve()
            && $this->workflow_status === self::WORKFLOW_STATUS_ACCOUNTING_VALIDATION;
    }

    public function requiresManualEarlyTerminationExecution(): bool
    {
        return $this->isWorkflowOrigin()
            && $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

    public function canSubmitManualEarlyTerminationConfirmation(): bool
    {
        return $this->isWorkflowOrigin()
            && ! $this->isTerminal()
            && $this->workflow_status === self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING
            && $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

    public function manualEarlyTerminationSubmitted(): bool
    {
        return $this->isWorkflowOrigin()
            && ! $this->isTerminal()
            && $this->workflow_status === self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED
            && $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

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
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    /**
     * @return HasMany<InsuranceReceivableFieldChangeLog, $this>
     */
    public function fieldChangeLogs(): HasMany
    {
        return $this->hasMany(InsuranceReceivableFieldChangeLog::class);
    }

    /**
     * @return HasOne<InsuranceReceivableInstallmentRepayment, $this>
     */
    public function installmentRepayment(): HasOne
    {
        return $this->hasOne(InsuranceReceivableInstallmentRepayment::class);
    }

    /**
     * @return HasMany<EarlyTerminationTransaction, $this>
     */
    public function earlyTerminationTransactions(): HasMany
    {
        return $this->hasMany(EarlyTerminationTransaction::class);
    }

    /**
     * @return HasMany<EarlyTerminationBalanceInquiry, $this>
     */
    public function earlyTerminationBalanceInquiries(): HasMany
    {
        return $this->hasMany(EarlyTerminationBalanceInquiry::class);
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function latestEarlyTerminationBalanceInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class)->latestOfMany();
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function latestPreTopUpInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where(
                'context',
                EarlyTerminationBalanceInquiry::CONTEXT_PRE_TOP_UP,
            ));
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function latestRetryPreTopUpInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where(
                'context',
                EarlyTerminationBalanceInquiry::CONTEXT_RETRY_PRE_TOP_UP,
            ));
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function latestPostTopUpVerificationInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where(
                'context',
                EarlyTerminationBalanceInquiry::CONTEXT_POST_TOP_UP_VERIFICATION,
            ));
    }

    /**
     * @return HasOne<EarlyTerminationBalanceInquiry, $this>
     */
    public function latestCalculationInquiry(): HasOne
    {
        return $this->hasOne(EarlyTerminationBalanceInquiry::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereNotNull('total_funding_amount'));
    }

    /**
     * @return HasMany<GlToGlTransaction, $this>
     */
    public function glToGlTransactions(): HasMany
    {
        return $this->hasMany(GlToGlTransaction::class);
    }

    /**
     * @return HasOne<GlToGlTransaction, $this>
     */
    public function latestEarlyTerminationTopUpTransaction(): HasOne
    {
        return $this->hasOne(GlToGlTransaction::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereIn('purpose', [
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP,
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            ]));
    }

    /**
     * @return HasOne<GlToGlTransaction, $this>
     */
    public function latestEarlyTerminationFlatSpreadTopUpTransaction(): HasOne
    {
        return $this->hasOne(GlToGlTransaction::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where(
                'purpose',
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            ));
    }

    /**
     * @return HasOne<GlToGlTransaction, $this>
     */
    public function latestEarlyTerminationContractTopUpTransaction(): HasOne
    {
        return $this->hasOne(GlToGlTransaction::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where(
                'purpose',
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            ));
    }

    /**
     * @return MorphMany<ApiIntegrationLog, $this>
     */
    public function apiIntegrationLogs(): MorphMany
    {
        return $this->morphMany(ApiIntegrationLog::class, 'related');
    }

    /**
     * @return BelongsTo<ApiIntegrationLog, $this>
     */
    public function contractOutstandingApiLog(): BelongsTo
    {
        return $this->belongsTo(ApiIntegrationLog::class, 'contract_outstanding_api_log_id');
    }

    /**
     * @return HasMany<ReceivablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class, 'insurance_receivable_id');
    }

    /**
     * @return HasMany<ReceivablePaymentRequest, $this>
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(ReceivablePaymentRequest::class, 'insurance_receivable_id');
    }

    /**
     * @return HasMany<ClaimStatusChangeRequest, $this>
     */
    public function claimStatusChangeRequests(): HasMany
    {
        return $this->hasMany(ClaimStatusChangeRequest::class);
    }

    /**
     * @return HasMany<InsuranceCoverLetter, $this>
     */
    public function insuranceCoverLetters(): HasMany
    {
        return $this->hasMany(InsuranceCoverLetter::class);
    }

    /**
     * @return HasMany<CkpnWorkpaperItem, $this>
     */
    public function ckpnWorkpaperItems(): HasMany
    {
        return $this->hasMany(CkpnWorkpaperItem::class, 'insurance_receivable_id');
    }

    /**
     * @return HasManyThrough<CkpnAdjustment, CkpnWorkpaperItem, $this>
     */
    public function ckpnAdjustments(): HasManyThrough
    {
        return $this->hasManyThrough(
            CkpnAdjustment::class,
            CkpnWorkpaperItem::class,
            'insurance_receivable_id',
            'ckpn_workpaper_item_id',
            'id',
            'id',
        );
    }

    /**
     * @return HasMany<InsuranceReceivableStageLog, $this>
     */
    public function stageLogs(): HasMany
    {
        return $this->hasMany(InsuranceReceivableStageLog::class)->latest('created_at')->latest('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin_type' => 'string',
            'date_of_death' => 'date',
            'credit_limit' => 'decimal:2',
            'loan_outstanding' => 'decimal:2',
            'contract_outstanding_amount' => 'decimal:2',
            'contract_outstanding_requested_as_of' => 'date',
            'contract_outstanding_as_of' => 'date',
            'dpd' => 'integer',
            'start_period' => 'date',
            'end_period' => 'date',
            'receivable_formation_date' => 'date',
            'receivable_amount' => 'decimal:2',
            'remaining_receivable_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'inquiry_completed_at' => 'datetime',
            'early_termination_executed_at' => 'datetime',
            'early_termination_resolved_at' => 'datetime',
        ];
    }
}
