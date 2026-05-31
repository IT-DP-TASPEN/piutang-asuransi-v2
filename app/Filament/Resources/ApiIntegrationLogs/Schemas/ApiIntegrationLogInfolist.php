<?php

namespace App\Filament\Resources\ApiIntegrationLogs\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ApiIntegrationLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('service_name')
                    ->label('Service'),
                TextEntry::make('endpoint'),
                TextEntry::make('method'),
                TextEntry::make('response_status')
                    ->label('HTTP status'),
                TextEntry::make('response_code')
                    ->label('Response code'),
                TextEntry::make('response_description')
                    ->label('Response description'),
                IconEntry::make('is_success')
                    ->label('Success')
                    ->boolean(),
                TextEntry::make('request_headers')
                    ->label('Request headers')
                    ->formatStateUsing(fn (array|string|null $state): ?string => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $state)
                    ->columnSpanFull(),
                TextEntry::make('request_body')
                    ->label('Request body')
                    ->columnSpanFull(),
                TextEntry::make('response_body')
                    ->label('Response body')
                    ->columnSpanFull(),
                TextEntry::make('error_message')
                    ->label('Error')
                    ->columnSpanFull(),
            ]);
    }
}
