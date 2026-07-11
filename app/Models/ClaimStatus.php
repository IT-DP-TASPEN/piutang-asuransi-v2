<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'is_default', 'is_terminal', 'is_active'])]
class ClaimStatus extends Model
{
    public const ON_PROCESS_CODE = 'on_process';

    public const APPROVED_CODE = 'approved';

    public const REJECTED_CODE = 'rejected';

    public const DEFAULT_CODE = self::ON_PROCESS_CODE;

    public const DECISION_CODES = [
        self::ON_PROCESS_CODE,
        self::APPROVED_CODE,
        self::REJECTED_CODE,
    ];

    public const LABELS = [
        self::ON_PROCESS_CODE => 'ON PROSES',
        self::APPROVED_CODE => 'DISETUJUI ASURANSI',
        self::REJECTED_CODE => 'DITOLAK ASURANSI',
    ];

    /**
     * @return array<string, string>
     */
    public static function decisionOptions(): array
    {
        return self::LABELS;
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
            'is_default' => 'boolean',
            'is_terminal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
