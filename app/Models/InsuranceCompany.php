<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'claim_type',
    'legal_name',
    'letter_recipient_name',
    'letter_recipient_address',
    'ckpn_weight',
    'sla_description',
    'is_active',
])]
class InsuranceCompany extends Model
{
    public const CLAIM_TYPE_AJK = 'ajk';

    public const CLAIM_TYPE_CREDIT = 'credit';

    /**
     * @return array<string, string>
     */
    public static function claimTypeOptions(): array
    {
        return [
            self::CLAIM_TYPE_AJK => 'AJK',
            self::CLAIM_TYPE_CREDIT => 'Credit Insurance',
        ];
    }

    public function isAjk(): bool
    {
        return $this->claim_type === self::CLAIM_TYPE_AJK;
    }

    public function isCreditInsurance(): bool
    {
        return $this->claim_type === self::CLAIM_TYPE_CREDIT;
    }

    public function resolvedLetterRecipientName(): string
    {
        return $this->letter_recipient_name
            ?? $this->legal_name
            ?? $this->name;
    }

    /**
     * @return HasMany<InsuranceReceivable, $this>
     */
    public function insuranceReceivables(): HasMany
    {
        return $this->hasMany(InsuranceReceivable::class);
    }

    /**
     * @return HasMany<LegacyReceivable, $this>
     */
    public function legacyReceivables(): HasMany
    {
        return $this->hasMany(LegacyReceivable::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ckpn_weight' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
