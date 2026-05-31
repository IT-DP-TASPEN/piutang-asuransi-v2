<?php

namespace App\Filament\Resources\BranchOffices\Pages;

use App\Filament\Resources\BranchOffices\BranchOfficeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranchOffice extends EditRecord
{
    protected static string $resource = BranchOfficeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
