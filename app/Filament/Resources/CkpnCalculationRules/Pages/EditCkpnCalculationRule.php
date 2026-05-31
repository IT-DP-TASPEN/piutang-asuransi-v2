<?php

namespace App\Filament\Resources\CkpnCalculationRules\Pages;

use App\Filament\Resources\CkpnCalculationRules\CkpnCalculationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCkpnCalculationRule extends EditRecord
{
    protected static string $resource = CkpnCalculationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
