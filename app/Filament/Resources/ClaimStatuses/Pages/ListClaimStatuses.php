<?php

namespace App\Filament\Resources\ClaimStatuses\Pages;

use App\Filament\Resources\ClaimStatuses\ClaimStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaimStatuses extends ListRecords
{
    protected static string $resource = ClaimStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
