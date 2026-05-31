<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'from_stage',
    'to_stage',
    'from_status',
    'to_status',
    'event',
    'description',
    'triggered_by_type',
    'triggered_by_id',
    'approval_request_id',
    'api_integration_log_id',
    'metadata',
])]
class InsuranceReceivableStageLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * @return BelongsTo<ApiIntegrationLog, $this>
     */
    public function apiIntegrationLog(): BelongsTo
    {
        return $this->belongsTo(ApiIntegrationLog::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
