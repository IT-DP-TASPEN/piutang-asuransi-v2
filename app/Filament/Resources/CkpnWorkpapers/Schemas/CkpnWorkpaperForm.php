<?php

namespace App\Filament\Resources\CkpnWorkpapers\Schemas;

use App\Models\CkpnWorkpaper;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnWorkpaperForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Workpaper')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('period')
                            ->required(),
                        Select::make('branch_office_id')
                            ->label('Branch')
                            ->relationship('branchOffice', 'branch_name')
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->options(CkpnWorkpaper::statusOptions())
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('total_receivable_amount')
                            ->label('Total receivable')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('total_ckpn_amount')
                            ->label('Total CKPN')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
