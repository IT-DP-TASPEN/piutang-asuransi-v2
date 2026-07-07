<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'approvable_type',
    'approvable_id',
    'workflow_code',
    'status',
    'submitted_by',
    'submitted_at',
    'final_approved_at',
])]
class ApprovalRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const WORKFLOW_CLAIM_SUBMISSION_BRANCH = 'claim_submission_branch';

    public const WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION = 'accounting_receivable_validation';

    public const WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION = 'manual_early_termination_verification';

    public const WORKFLOW_CLAIM_STATUS_UPDATE = 'claim_status_update';

    public const WORKFLOW_MONTHLY_CKPN_WORKPAPER = 'monthly_ckpn_workpaper';

    public const WORKFLOW_CKPN_JOURNAL_APPROVAL = 'ckpn_journal_approval';

    public const WORKFLOW_CKPN_ADJUSTMENT = 'ckpn_adjustment';

    public const WORKFLOW_RECEIVABLE_PAYMENT = 'receivable_payment';

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class);
    }

    /**
     * @return HasMany<ApprovalLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'final_approved_at' => 'datetime',
        ];
    }
}
