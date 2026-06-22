<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'insurance_receivable_id',
    'claim_type',
    'letter_number',
    'sequence_base',
    'sequence_number',
    'letter_date',
    'template_key',
    'insurance_company_id',
    'recipient_name',
    'recipient_address',
    'subject',
    'rendered_html',
    'generated_file_path',
    'status',
    'created_by',
])]
class InsuranceCoverLetter extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    /** @var list<string> */
    private const IMMUTABLE_ATTRIBUTES = [
        'insurance_receivable_id',
        'claim_type',
        'letter_number',
        'sequence_base',
        'sequence_number',
        'letter_date',
        'template_key',
        'insurance_company_id',
        'recipient_name',
        'recipient_address',
        'subject',
        'rendered_html',
    ];

    /**
     * @return BelongsTo<InsuranceReceivable, $this>
     */
    public function insuranceReceivable(): BelongsTo
    {
        return $this->belongsTo(InsuranceReceivable::class);
    }

    /**
     * @return BelongsTo<InsuranceCompany, $this>
     */
    public function insuranceCompany(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'letter_date' => 'date',
            'sequence_base' => 'integer',
            'sequence_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (InsuranceCoverLetter $letter): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if (! $letter->isDirty($attribute) || $letter->getRawOriginal($attribute) === null) {
                    continue;
                }

                throw new \LogicException("Insurance cover letter {$attribute} is immutable.");
            }
        });
    }
}
