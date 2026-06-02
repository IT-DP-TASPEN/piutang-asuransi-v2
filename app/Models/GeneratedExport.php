<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'exportable_type',
    'exportable_id',
    'export_type',
    'file_path',
    'status',
    'generated_by',
    'generated_at',
    'metadata',
])]
class GeneratedExport extends Model
{
    public const DISK = 'local';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    public const TYPE_CKPN_WORKPAPER_SAKEP_XLSX = 'ckpn_workpaper_sakep_xlsx';

    /**
     * @return MorphTo<Model, $this>
     */
    public function exportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
