<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purpose',
    'ckpn_journal_id',
    'ckpn_workpaper_id',
    'insurance_receivable_id',
    'reference_number',
    'receipt_number',
    'request_payload',
    'response_payload',
    'response_code',
    'response_description',
    'status',
    'executed_by',
    'executed_at',
])]
class GlToGlTransaction extends Model
{
    public const PURPOSE_CKPN_JOURNAL = 'ckpn_journal';

    public const PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP = 'early_termination_repayment_top_up';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<CkpnJournal, $this>
     */
    public function ckpnJournal(): BelongsTo
    {
        return $this->belongsTo(CkpnJournal::class);
    }

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
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
