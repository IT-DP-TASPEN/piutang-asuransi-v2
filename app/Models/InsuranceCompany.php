<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'ckpn_weight', 'sla_description', 'is_active'])]
class InsuranceCompany extends Model
{
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
