<?php

namespace App\Filament\Resources\GlToGlTransactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class GlToGlTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction details')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('reference_number')
                            ->label('Reference number')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('receipt_number')
                            ->label('Receipt number')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('response_code')->label('Response code'),
                        TextEntry::make('response_description')->label('Response description'),
                        TextEntry::make('executor.name')->label('Executed by'),
                        TextEntry::make('executed_at')->dateTime(),
                        TextEntry::make('request_payload')
                            ->formatStateUsing(fn($state) => self::formatJson($state))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('response_payload')
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
