<?php

namespace App\Models;

use Database\Factories\BranchOfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_code', 'branch_name', 'is_active'])]
class BranchOffice extends Model
{
    /** @use HasFactory<BranchOfficeFactory> */
    use HasFactory;

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<InsuranceReceivable, $this>
     */
    public function insuranceReceivables(): HasMany
    {
        return $this->hasMany(InsuranceReceivable::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
