<?php

namespace App\Filament\Resources\CkpnJournals\Pages;

use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use Filament\Resources\Pages\ListRecords;

class ListCkpnJournals extends ListRecords
{
    protected static string $resource = CkpnJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
