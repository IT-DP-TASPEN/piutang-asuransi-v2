<?php

namespace App\Filament\Resources\CkpnJournals\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnJournalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Journal')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('ckpnWorkpaper.period')->label('Workpaper period')->date(),
                        TextEntry::make('branchOffice.branch_name')->label('Branch')->placeholder('All branches'),
                        TextEntry::make('journal_date')->date(),
                        TextEntry::make('total_amount')->numeric(2),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('debit_account'),
                        TextEntry::make('credit_account'),
                        TextEntry::make('debit_narrative')->columnSpanFull(),
                        TextEntry::make('credit_narrative')->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull(),
                        TextEntry::make('creator.name')->label('Created by'),
                        TextEntry::make('approver.name')->label('Approved by'),
                        TextEntry::make('approved_at')->dateTime(),
                    ]),
            ]);
    }
}
