<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'ckpn_weight', 'is_default', 'is_terminal', 'is_active'])]
class ClaimStatus extends Model
{
    public const DEFAULT_CODE = 'on_process';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ckpn_weight' => 'decimal:4',
            'is_default' => 'boolean',
            'is_terminal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
