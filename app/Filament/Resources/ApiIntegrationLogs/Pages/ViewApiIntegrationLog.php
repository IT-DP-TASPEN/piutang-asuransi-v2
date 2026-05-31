<?php

namespace App\Filament\Resources\ApiIntegrationLogs\Pages;

use App\Filament\Resources\ApiIntegrationLogs\ApiIntegrationLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewApiIntegrationLog extends ViewRecord
{
    protected static string $resource = ApiIntegrationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
