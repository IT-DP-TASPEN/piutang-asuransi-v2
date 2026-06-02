<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use App\Models\ClaimStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsuranceReceivableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Draft')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('loan_account_number')
                            ->label('Loan account number')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('cif_no')
                            ->label('CIF')
                            ->maxLength(255),
                        DatePicker::make('date_of_death')
                            ->label('Date of death')
                            ->required(),
                        Select::make('insurance_company_id')
                            ->label('Insurance company')
                            ->relationship('insuranceCompany', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('claim_status_id')
                            ->label('Claim status')
                            ->relationship('claimStatus', 'name')
                            ->default(fn (): ?int => ClaimStatus::query()
                                ->where('code', ClaimStatus::DEFAULT_CODE)
                                ->value('id'))
                            ->disabled()
                            ->dehydrated()
                            ->required(),
                    ]),
                Section::make('Required documents')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        FileUpload::make('supporting_document_file_path')
                            ->label('Supporting document')
                            ->disk('public')
                            ->directory('insurance-receivable-documents')
                            ->storeFileNamesIn('supporting_document_original_filename')
                            ->required(),
                    ])
                    ->visibleOn('create'),
                Section::make('Inquiry snapshot')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('branch_code')
                            ->label('Branch code')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('customer_name')
                            ->label('Customer name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('alt_number')
                            ->label('Alt number')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('cif_no_alt')
                            ->label('CIF alt')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('loan_outstanding')
                            ->label('Loan outstanding')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('receivable_amount')
                            ->label('Receivable amount')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('credit_limit')
                            ->label('Credit limit')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('collectability')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('dpd')
                            ->label('DPD')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('product_id')
                            ->label('Product ID')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('product_name')
                            ->label('Product name')
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('start_period')
                            ->label('Start period')
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('end_period')
                            ->label('End period')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('workflow_status')
                            ->label('Workflow status')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('system_status')
                            ->label('System status')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_error_message')
                            ->label('Last error')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->visibleOn('edit'),
            ]);
    }
}
