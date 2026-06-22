<?php

namespace App\Filament\Resources\ClaimDocumentRequirements\Pages;

use App\Filament\Resources\ClaimDocumentRequirements\ClaimDocumentRequirementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClaimDocumentRequirements extends ListRecords
{
    protected static string $resource = ClaimDocumentRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
