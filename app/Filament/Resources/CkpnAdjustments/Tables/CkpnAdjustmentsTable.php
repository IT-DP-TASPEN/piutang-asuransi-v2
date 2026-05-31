<?php

namespace App\Filament\Resources\CkpnAdjustments\Tables;

use App\Models\CkpnAdjustment;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CkpnAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('insuranceReceivable.branch_code')
                    ->label('Branch')
                    ->sortable(),
                TextColumn::make('insuranceReceivable.loan_account_number')
                    ->label('Loan account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('adjustment_type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_rate')->numeric(4)->suffix('%'),
                TextColumn::make('adjusted_rate')->numeric(4)->suffix('%'),
                TextColumn::make('original_amount')->numeric(2),
                TextColumn::make('adjusted_amount')->numeric(2),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('requester.name')->label('Requested by')->sortable(),
                TextColumn::make('approver.name')->label('Approved by')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CkpnAdjustment::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
