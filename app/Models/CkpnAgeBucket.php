<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'min_days', 'max_days', 'ckpn_weight', 'is_active'])]
class CkpnAgeBucket extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_days' => 'integer',
            'max_days' => 'integer',
            'ckpn_weight' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
