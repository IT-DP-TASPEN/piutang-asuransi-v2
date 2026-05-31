<?php

namespace App\Filament\Resources\GeneratedExports\Pages;

use App\Filament\Resources\GeneratedExports\GeneratedExportResource;
use Filament\Resources\Pages\ListRecords;

class ListGeneratedExports extends ListRecords
{
    protected static string $resource = GeneratedExportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
