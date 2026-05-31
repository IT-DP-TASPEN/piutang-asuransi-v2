<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'field_name',
    'old_value',
    'new_value',
    'changed_by',
    'reason',
])]
class InsuranceReceivableFieldChangeLog extends Model
{
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
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
