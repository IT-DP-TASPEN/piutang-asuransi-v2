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
                TextColumn::make('ckpnWorkpaperItem.source_label')
                    ->label('Source')
                    ->badge(),
                TextColumn::make('ckpnWorkpaperItem.branch_code')
                    ->label('Branch')
                    ->sortable(),
                TextColumn::make('ckpnWorkpaperItem.loan_account_number')
                    ->label('Loan account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ckpnWorkpaperItem.customer_name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('adjustment_type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('calculated_ckpn_rate')->numeric(4)->suffix('%'),
                TextColumn::make('calculated_ckpn_amount')->numeric(2),
                TextColumn::make('requested_adjusted_ckpn_rate')->numeric(4)->suffix('%'),
                TextColumn::make('requested_adjusted_ckpn_amount')->numeric(2),
                TextColumn::make('approved_adjusted_ckpn_rate')->numeric(4)->suffix('%')->toggleable(),
                TextColumn::make('approved_adjusted_ckpn_amount')->numeric(2)->toggleable(),
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
