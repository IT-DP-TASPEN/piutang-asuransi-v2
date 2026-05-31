<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInsuranceReceivable extends EditRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
