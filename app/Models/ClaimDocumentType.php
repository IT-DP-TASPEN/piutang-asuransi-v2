<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'description',
    'accepted_file_types',
    'sort_order',
    'active',
])]
class ClaimDocumentType extends Model
{
    /**
     * @return HasMany<ClaimDocumentRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(ClaimDocumentRequirement::class);
    }

    /**
     * @return HasMany<InsuranceReceivableDocument, $this>
     */
    public function receivableDocuments(): HasMany
    {
        return $this->hasMany(InsuranceReceivableDocument::class);
    }

    protected function casts(): array
    {
        return [
            'accepted_file_types' => 'array',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }
}
