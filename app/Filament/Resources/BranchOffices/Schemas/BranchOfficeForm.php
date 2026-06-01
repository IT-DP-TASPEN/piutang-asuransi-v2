<?php

namespace App\Filament\Resources\BranchOffices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchOfficeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branch Office')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('branch_code')
                            ->label('Branch code')
                            ->required()
                            ->maxLength(3)
                            ->regex('/^\d{3}$/')
                            ->unique(ignoreRecord: true),
                        TextInput::make('branch_name')
                            ->label('Branch name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }
}
