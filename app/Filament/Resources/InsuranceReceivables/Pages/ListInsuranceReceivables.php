<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInsuranceReceivables extends ListRecords
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
