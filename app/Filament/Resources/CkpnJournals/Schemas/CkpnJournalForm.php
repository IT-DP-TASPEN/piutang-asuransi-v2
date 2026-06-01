<?php

namespace App\Filament\Resources\CkpnJournals\Schemas;

use App\Models\CkpnJournal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnJournalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Journal')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        Select::make('ckpn_workpaper_id')
                            ->label('Workpaper')
                            ->relationship('ckpnWorkpaper', 'period')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('branch_office_id')
                            ->label('Branch')
                            ->relationship('branchOffice', 'branch_name')
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('journal_date')
                            ->required(),
                        TextInput::make('total_amount')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        Select::make('status')
                            ->options(CkpnJournal::statusOptions())
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('debit_account')
                            ->maxLength(255),
                        TextInput::make('credit_account')
                            ->maxLength(255),
                        Textarea::make('debit_narrative')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Textarea::make('credit_narrative')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
