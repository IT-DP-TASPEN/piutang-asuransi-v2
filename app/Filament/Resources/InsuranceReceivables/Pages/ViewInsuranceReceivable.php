<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInsuranceReceivable extends ViewRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
