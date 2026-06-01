<?php

namespace App\Filament\Resources\CkpnAgeBuckets\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnAgeBucketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('min_days')
                            ->label('Min days')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('max_days')
                            ->label('Max days')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('ckpn_weight')
                            ->label('CKPN weight (%)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.0001'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }
}
