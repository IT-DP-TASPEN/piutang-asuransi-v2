<?php

namespace App\Filament\Resources\ClaimDocumentTypes\Pages;

use App\Filament\Resources\ClaimDocumentTypes\ClaimDocumentTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaimDocumentTypes extends ListRecords
{
    protected static string $resource = ClaimDocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
