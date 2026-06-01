<?php

namespace App\Filament\Resources\ApiIntegrationLogs\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ApiIntegrationLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Log details')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
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
                            ->formatStateUsing(fn($state) => self::formatJson($state))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('request_body')
                            ->label('Request body')
                            ->formatStateUsing(fn($state) => self::formatJson($state))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('response_body')
                            ->label('Response body')
                            ->formatStateUsing(fn($state) => self::formatJson($state))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('error_message')
                            ->label('Error')
                            ->formatStateUsing(fn($state) => self::formatJson($state))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function formatJson(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        if (is_string($state)) {
            return $state;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
