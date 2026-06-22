<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'claim_document_type_id',
    'file_path',
    'original_file_name',
    'mime_type',
    'uploaded_by',
    'uploaded_at',
])]
class InsuranceReceivableDocument extends Model
{
    public const DISK = InsuranceReceivable::DOCUMENT_DISK;

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<ClaimDocumentType, $this>
     */
    public function claimDocumentType(): BelongsTo
    {
        return $this->belongsTo(ClaimDocumentType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }
}
