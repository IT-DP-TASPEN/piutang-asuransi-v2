<?php

namespace App\Filament\Resources\LegacyReceivables\Pages;

use App\Actions\LegacyReceivable\RecalculateLegacyReceivableRemainingAmountAction;
use App\Filament\Resources\LegacyReceivables\LegacyReceivableResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLegacyReceivable extends EditRecord
{
    protected static string $resource = LegacyReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(RecalculateLegacyReceivableRemainingAmountAction::class)->handle($this->getRecord());
    }
}
