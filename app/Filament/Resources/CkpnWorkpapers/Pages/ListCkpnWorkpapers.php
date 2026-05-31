<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCkpnWorkpapers extends ListRecords
{
    protected static string $resource = CkpnWorkpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
