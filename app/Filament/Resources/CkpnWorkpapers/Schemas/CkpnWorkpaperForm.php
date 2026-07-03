<?php

namespace App\Filament\Resources\CkpnWorkpapers\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                            ->label('Tanggal Cutoff')
                            ->required(),
                        Select::make('branch_office_id')
                            ->label('Branch')
                            ->relationship(
                                'branchOffice',
                                'branch_name',
                                fn ($query) => $query->where('is_active', true)->orderBy('branch_code'),
                            )
                            ->searchable()
                            ->required()
                            ->preload(),
                    ]),
            ]);
    }
}
