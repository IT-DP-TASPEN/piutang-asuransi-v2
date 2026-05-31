<?php

namespace App\Filament\Resources\GlToGlTransactions\Pages;

use App\Filament\Resources\GlToGlTransactions\GlToGlTransactionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGlToGlTransaction extends ViewRecord
{
    protected static string $resource = GlToGlTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
