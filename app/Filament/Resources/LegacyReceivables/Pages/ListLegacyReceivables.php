<?php

namespace App\Filament\Resources\LegacyReceivables\Pages;

use App\Filament\Resources\LegacyReceivables\LegacyReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegacyReceivables extends ListRecords
{
    protected static string $resource = LegacyReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
