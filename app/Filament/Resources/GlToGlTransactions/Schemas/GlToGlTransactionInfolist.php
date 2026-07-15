<?php

namespace App\Filament\Resources\GlToGlTransactions\Schemas;

use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Phiki\Grammar\Grammar;

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
                        TextEntry::make('purpose')->badge(),
                        TextEntry::make('insuranceReceivable.loan_account_number')
                            ->label('Insurance receivable loan account')
                            ->placeholder('-'),
                        TextEntry::make('reference_number')
                            ->label('Reference number')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('attempt_no')
                            ->label('Attempt no')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('receipt_number')
                            ->label('Receipt number')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('resolution_status')->label('Resolution status')->badge()->placeholder('-'),
                        TextEntry::make('resolution_outcome')->label('Resolution outcome')->badge()->placeholder('-'),
                        TextEntry::make('resolution_reason')->label('Resolution reason')->placeholder('-'),
                        TextEntry::make('resolver.name')->label('Resolved by')->placeholder('-'),
                        TextEntry::make('resolved_at')->dateTime()->placeholder('-'),
                        TextEntry::make('response_code')->label('Response code'),
                        TextEntry::make('response_description')->label('Response description'),
                        TextEntry::make('executor.name')->label('Executed by'),
                        TextEntry::make('executed_at')->dateTime(),
                        CodeEntry::make('request_payload')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        CodeEntry::make('response_payload')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        CodeEntry::make('resolution_payload')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
