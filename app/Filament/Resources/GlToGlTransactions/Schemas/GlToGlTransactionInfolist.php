<?php

namespace App\Filament\Resources\GlToGlTransactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GlToGlTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference_number')->label('Reference number'),
                TextEntry::make('receipt_number')->label('Receipt number'),
                TextEntry::make('status')->badge(),
                TextEntry::make('response_code')->label('Response code'),
                TextEntry::make('response_description')->label('Response description'),
                TextEntry::make('executor.name')->label('Executed by'),
                TextEntry::make('executed_at')->dateTime(),
                TextEntry::make('request_payload')
                    ->formatStateUsing(fn (array|string|null $state): ?string => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $state)
                    ->columnSpanFull(),
                TextEntry::make('response_payload')
                    ->formatStateUsing(fn (array|string|null $state): ?string => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $state)
                    ->columnSpanFull(),
            ]);
    }
}
