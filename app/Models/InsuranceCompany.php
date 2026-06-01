<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'ckpn_weight', 'sla_description', 'is_active'])]
class InsuranceCompany extends Model
{
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
