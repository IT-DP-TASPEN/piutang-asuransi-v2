<?php

namespace App\Filament\Resources\LegacyReceivables\Pages;

use App\Filament\Resources\LegacyReceivables\LegacyReceivableResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLegacyReceivable extends ViewRecord
{
    protected static string $resource = LegacyReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
