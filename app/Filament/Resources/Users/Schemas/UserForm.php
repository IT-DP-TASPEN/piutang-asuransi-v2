<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Details')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('branch_office_id')
                            ->relationship(
                                'branchOffice',
                                'branch_name',
                                fn($query) => $query->where('is_active', true)->orderBy('branch_code'),
                            )
                            ->required(),
                        // TextInput::make('username')
                        //     ->required()
                        //     ->maxLength(255)
                        //     ->unique(ignoreRecord: true),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->label('Password')
                            ->minLength(8)
                            ->required(fn(string $context) => $context === 'create')
                            ->confirmed()
                            ->dehydrated(fn($state) => filled($state))
                            ->dehydrateStateUsing(fn($state) => Hash::make($state)),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->revealable()
                            ->label('Confirm Password')
                            ->required(fn(string $context) => $context === 'create')
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
