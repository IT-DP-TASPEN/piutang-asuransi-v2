<?php

namespace App\Filament\Resources\CkpnCalculationRules\Pages;

use App\Filament\Resources\CkpnCalculationRules\CkpnCalculationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCkpnCalculationRules extends ListRecords
{
    protected static string $resource = CkpnCalculationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
