<?php

namespace App\Filament\Resources\CkpnAdjustments\Pages;

use App\Filament\Resources\CkpnAdjustments\CkpnAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCkpnAdjustments extends ListRecords
{
    protected static string $resource = CkpnAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
