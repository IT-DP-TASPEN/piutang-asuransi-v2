<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\CkpnWorkpaper;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCkpnWorkpaper extends EditRecord
{
    protected static string $resource = CkpnWorkpaperResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(in_array($this->getRecord()->status, [
            CkpnWorkpaper::STATUS_DRAFT,
            CkpnWorkpaper::STATUS_RETURNED,
        ], true), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
