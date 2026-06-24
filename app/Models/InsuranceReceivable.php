<?php

namespace App\Models;

use Database\Factories\InsuranceReceivableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    'death_document_condition',
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

    public const WORKFLOW_STATUS_DRAFT = 'draft';

    public const WORKFLOW_STATUS_SUBMITTED = 'submitted';

    public const WORKFLOW_STATUS_BRANCH_APPROVED = 'branch_approved';

    public const WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING = 'collectability_confirmation_pending';

    public const WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING = 'accounting_validation_pending';

    public const WORKFLOW_STATUS_RETURNED = 'returned';

    public const WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER = 'returned_to_branch_maker';

    public const WORKFLOW_STATUS_RETURNED_TO_ACCOUNTING_MAKER = 'returned_to_accounting_maker';

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

    public const SYSTEM_STATUS_BRANCH_VALIDATION_FAILED = 'branch_validation_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_QUEUED = 'early_termination_queued';

    public const SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING = 'early_termination_confirmation_pending';

    public const SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED = 'early_termination_manual_execution_required';

    public const SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED = 'early_termination_top_up_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED = 'early_termination_top_up_executed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING = 'early_termination_processing';

    public const SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED = 'early_termination_executed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_FAILED = 'early_termination_failed';

    public const SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED = 'early_termination_resolved';

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
            self::WORKFLOW_STATUS_BRANCH_APPROVED => 'Branch approved',
            self::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING => 'Collectability confirmation pending',
            self::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING => 'Accounting validation pending',
            self::WORKFLOW_STATUS_RETURNED => 'Returned',
            self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER => 'Returned to branch maker',
            self::WORKFLOW_STATUS_RETURNED_TO_ACCOUNTING_MAKER => 'Returned to accounting maker',
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
    public static function systemStatusOptions(): array
    {
        return [
            self::SYSTEM_STATUS_INQUIRY_QUEUED => 'Inquiry queued',
            self::SYSTEM_STATUS_INQUIRY_PROCESSING => 'Inquiry processing',
            self::SYSTEM_STATUS_INQUIRY_COMPLETED => 'Inquiry completed',
            self::SYSTEM_STATUS_INQUIRY_FAILED => 'Inquiry failed',
            self::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED => 'Branch validation failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED => 'Early termination queued',
            self::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING => 'Early termination confirmation pending',
            self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED => 'Manual Early Termination Required',
            self::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED => 'Early termination top up failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED => 'Early termination top up executed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING => 'Early termination processing',
            self::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED => 'Early termination executed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_FAILED => 'Early termination failed',
            self::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED => 'Early termination resolved',
        ];
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
        return in_array($this->workflow_status, [
            self::WORKFLOW_STATUS_DRAFT,
            self::WORKFLOW_STATUS_RETURNED,
            self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ], true) && ! $this->isTerminal();
    }

    public function canRetryInquiry(): bool
    {
        return ! $this->isTerminal()
            && in_array($this->workflow_status, [
                self::WORKFLOW_STATUS_DRAFT,
                self::WORKFLOW_STATUS_RETURNED,
                self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)
            && $this->system_status === self::SYSTEM_STATUS_INQUIRY_FAILED;
    }

    public function canCancelFailedInquiry(): bool
    {
        return ! $this->isTerminal()
            && in_array($this->workflow_status, [
                self::WORKFLOW_STATUS_DRAFT,
                self::WORKFLOW_STATUS_RETURNED,
                self::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true)
            && $this->system_status === self::SYSTEM_STATUS_INQUIRY_FAILED;
    }

    public function canResolveEarlyTermination(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        if ($this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_FAILED) {
            return true;
        }

        return $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED
            && $this->workflow_status !== self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING;
    }

    public function requiresManualEarlyTerminationExecution(): bool
    {
        return $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

    public function canSubmitManualEarlyTerminationConfirmation(): bool
    {
        return ! $this->isTerminal()
            && $this->workflow_status === self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING
            && $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

    public function manualEarlyTerminationSubmitted(): bool
    {
        return ! $this->isTerminal()
            && $this->workflow_status === self::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED
            && $this->system_status === self::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED;
    }

    /**
     * @return array{reason: string, message: string, repayment_account: string}|null
     */
    public function manualEarlyTerminationRequirement(): ?array
    {
        $account = trim((string) $this->saving_account_for_loan_repayment);

        if ($account === '') {
            return [
                'reason' => 'empty_repayment_account',
                'message' => 'Manual Early Termination execution required because repayment saving account is empty.',
                'repayment_account' => $account,
            ];
        }

        if (str_contains(strtoupper($account), 'OPER')) {
            return [
                'reason' => 'oper_account',
                'message' => 'Manual Early Termination execution required for OPER account.',
                'repayment_account' => $account,
            ];
        }

        return null;
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
     * @return HasMany<ReceivableFormationJournal, $this>
     */
    public function receivableFormationJournals(): HasMany
    {
        return $this->hasMany(ReceivableFormationJournal::class);
    }

    /**
     * @return HasMany<EarlyTerminationTransaction, $this>
     */
    public function earlyTerminationTransactions(): HasMany
    {
        return $this->hasMany(EarlyTerminationTransaction::class);
    }

    /**
     * @return HasMany<GlToGlTransaction, $this>
     */
    public function glToGlTransactions(): HasMany
    {
        return $this->hasMany(GlToGlTransaction::class);
    }

    /**
     * @return MorphMany<ApiIntegrationLog, $this>
     */
    public function apiIntegrationLogs(): MorphMany
    {
        return $this->morphMany(ApiIntegrationLog::class, 'related');
    }

    /**
     * @return HasMany<ReceivablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class, 'insurance_receivable_id');
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
            'date_of_death' => 'date',
            'credit_limit' => 'decimal:2',
            'loan_outstanding' => 'decimal:2',
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
