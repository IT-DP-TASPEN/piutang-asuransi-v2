<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'claim_type',
    'claim_document_type_id',
    'is_required',
    'is_conditional',
    'condition_key',
    'sort_order',
])]
class ClaimDocumentRequirement extends Model
{
    /**
     * @return BelongsTo<ClaimDocumentType, $this>
     */
    public function claimDocumentType(): BelongsTo
    {
        return $this->belongsTo(ClaimDocumentType::class);
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_conditional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
