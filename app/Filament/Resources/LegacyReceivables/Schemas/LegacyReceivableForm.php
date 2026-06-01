<?php

namespace App\Filament\Resources\LegacyReceivables\Schemas;

use App\Models\ClaimStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LegacyReceivableForm
{
    public static function configure(Schema $schema)
    {
        return $schema
            ->components([
                Section::make('Legacy receivable')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('cif')
                            ->label('CIF')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('customer_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('loan_account_number')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('loan_alt_account_number')
                            ->maxLength(255),
                        Select::make('branch_office_id')
                            ->relationship('branchOffice', 'branch_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('insurance_company_id')
                            ->relationship('insuranceCompany', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('claim_status_id')
                            ->relationship('claimStatus', 'name')
                            ->default(fn(): ?int => ClaimStatus::query()
                                ->where('code', ClaimStatus::DEFAULT_CODE)
                                ->value('id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('date_of_death')
                            ->required(),
                        DatePicker::make('receivable_formation_date'),
                        TextInput::make('loan_outstanding')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        TextInput::make('original_receivable_amount')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        TextInput::make('remaining_receivable_amount')
                            ->numeric()
                            ->step('0.01')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
