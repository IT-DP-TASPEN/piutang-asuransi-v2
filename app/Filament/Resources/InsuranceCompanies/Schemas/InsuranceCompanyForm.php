<?php

namespace App\Filament\Resources\InsuranceCompanies\Schemas;

use App\Models\InsuranceCompany;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsuranceCompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->maxLength(255),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('claim_type')
                            ->label('Claim type')
                            ->options(InsuranceCompany::claimTypeOptions())
                            ->required(),
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->maxLength(255),
                        TextInput::make('letter_recipient_name')
                            ->label('Letter recipient name')
                            ->maxLength(255),
                        Textarea::make('letter_recipient_address')
                            ->label('Letter recipient address')
                            ->columnSpanFull(),
                        TextInput::make('ckpn_weight')
                            ->label('CKPN weight (%)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.0001'),
                        Textarea::make('sla_description')
                            ->label('Description')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }
}
