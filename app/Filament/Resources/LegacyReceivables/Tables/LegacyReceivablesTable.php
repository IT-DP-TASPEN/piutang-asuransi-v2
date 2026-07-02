<?php

namespace App\Filament\Resources\LegacyReceivables\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LegacyReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branchOffice.branch_code')->label('Branch')->searchable()->sortable(),
                TextColumn::make('cif')->label('CIF')->searchable()->sortable(),
                TextColumn::make('loan_account_number')->searchable()->sortable(),
                TextColumn::make('customer_name')->searchable()->sortable(),
                TextColumn::make('insuranceCompany.name')->label('Insurance')->searchable(),
                TextColumn::make('claimStatus.name')->label('Claim status')->badge(),
                TextColumn::make('original_receivable_amount')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('remaining_receivable_amount')->money('IDR', 0, 'id_ID')->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_office_id')
                    ->label('Branch')
                    ->relationship('branchOffice', 'branch_name'),
                SelectFilter::make('insurance_company_id')
                    ->label('Insurance company')
                    ->relationship('insuranceCompany', 'name'),
                SelectFilter::make('claim_status_id')
                    ->label('Claim status')
                    ->relationship('claimStatus', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
