<?php

namespace App\Filament\Resources\GlToGlTransactions\Pages;

use App\Filament\Resources\GlToGlTransactions\GlToGlTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListGlToGlTransactions extends ListRecords
{
    protected static string $resource = GlToGlTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
