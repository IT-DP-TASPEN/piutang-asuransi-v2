<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\CkpnWorkpaper;
use Filament\Resources\Pages\CreateRecord;

class CreateCkpnWorkpaper extends CreateRecord
{
    protected static string $resource = CkpnWorkpaperResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'status' => CkpnWorkpaper::STATUS_DRAFT,
            'created_by' => auth()->id(),
        ];
    }
}
