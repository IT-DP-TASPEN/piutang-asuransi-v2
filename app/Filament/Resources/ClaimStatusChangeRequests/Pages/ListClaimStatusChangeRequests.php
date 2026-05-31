<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests\Pages;

use App\Filament\Resources\ClaimStatusChangeRequests\ClaimStatusChangeRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaimStatusChangeRequests extends ListRecords
{
    protected static string $resource = ClaimStatusChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
