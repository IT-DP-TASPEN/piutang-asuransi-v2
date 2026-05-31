<?php

namespace App\Filament\Resources\InsuranceCompanies\Pages;

use App\Filament\Resources\InsuranceCompanies\InsuranceCompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInsuranceCompany extends EditRecord
{
    protected static string $resource = InsuranceCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
