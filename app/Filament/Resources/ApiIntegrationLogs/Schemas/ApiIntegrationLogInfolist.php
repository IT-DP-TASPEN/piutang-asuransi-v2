<?php

namespace App\Filament\Resources\ApiIntegrationLogs\Schemas;

use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Phiki\Grammar\Grammar;

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
                        CodeEntry::make('request_headers')
                            ->label('Request headers')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        CodeEntry::make('request_body')
                            ->label('Request body')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        CodeEntry::make('response_body')
                            ->label('Response body')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        CodeEntry::make('error_message')
                            ->label('Error')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
