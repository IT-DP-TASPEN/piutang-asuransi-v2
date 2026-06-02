<?php

namespace App\Filament\Resources\InsuranceReceivables\Tables;

use App\Models\InsuranceReceivable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InsuranceReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch_code')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('loan_account_number')
                    ->label('Loan account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('insuranceCompany.name')
                    ->label('Insurance')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('claimStatus.name')
                    ->label('Claim status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('loan_outstanding')
                    ->label('Outstanding')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->sortable(),
                TextColumn::make('system_status')
                    ->label('System')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_office_id')
                    ->label('Branch')
                    ->relationship('branchOffice', 'branch_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('insurance_company_id')
                    ->label('Insurance company')
                    ->relationship('insuranceCompany', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('claim_status_id')
                    ->label('Claim status')
                    ->relationship('claimStatus', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('workflow_status')
                    ->label('Workflow status')
                    ->options(InsuranceReceivable::workflowStatusOptions()),
                SelectFilter::make('system_status')
                    ->label('System status')
                    ->options(InsuranceReceivable::systemStatusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (InsuranceReceivable $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
