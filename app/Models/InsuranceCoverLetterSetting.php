<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sequence_base'])]
class InsuranceCoverLetterSetting extends Model
{
    public const SINGLETON_ID = 1;

    public static function sequenceBase(): int
    {
        return (int) static::query()->find(self::SINGLETON_ID)?->sequence_base;
    }

    protected function casts(): array
    {
        return [
            'sequence_base' => 'integer',
        ];
    }
}
