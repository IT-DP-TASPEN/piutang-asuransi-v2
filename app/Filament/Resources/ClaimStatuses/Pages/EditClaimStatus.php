<?php

namespace App\Filament\Resources\ClaimStatuses\Pages;

use App\Filament\Resources\ClaimStatuses\ClaimStatusResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClaimStatus extends EditRecord
{
    protected static string $resource = ClaimStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
