<?php

namespace App\Filament\Resources\BranchOffices\Pages;

use App\Filament\Resources\BranchOffices\BranchOfficeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranchOffices extends ListRecords
{
    protected static string $resource = BranchOfficeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
