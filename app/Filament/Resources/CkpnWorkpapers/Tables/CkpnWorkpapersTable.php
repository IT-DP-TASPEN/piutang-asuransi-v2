<?php

namespace App\Filament\Resources\CkpnWorkpapers\Tables;

use App\Models\CkpnWorkpaper;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CkpnWorkpapersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period')
                    ->date()
                    ->sortable(),
                TextColumn::make('branchOffice.branch_name')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_receivable_amount')
                    ->label('Total receivable')
                    ->money('IDR', 0, 'id_ID')
                    ->sortable(),
                TextColumn::make('total_calculated_ckpn_amount')
                    ->label('Calculated CKPN')
                    ->money('IDR', 0, 'id_ID')
                    ->sortable(),
                TextColumn::make('total_adjustment_delta')
                    ->label('Adjustment delta')
                    ->money('IDR', 0, 'id_ID')
                    ->sortable(),
                TextColumn::make('total_effective_ckpn_amount')
                    ->label('Effective CKPN')
                    ->money('IDR', 0, 'id_ID')
                    ->sortable(),
                TextColumn::make('total_ckpn_amount')
                    ->label('Final CKPN')
                    ->money('IDR', 0, 'id_ID')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->money('IDR', 0, 'id_ID'),
                    )
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('approver.name')
                    ->label('Approved by')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_office_id')
                    ->label('Branch')
                    ->relationship('branchOffice', 'branch_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(CkpnWorkpaper::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn(CkpnWorkpaper $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && in_array($record->status, [
                            CkpnWorkpaper::STATUS_DRAFT,
                            CkpnWorkpaper::STATUS_RETURNED,
                        ], true)),
                DeleteAction::make()
                    ->visible(fn(CkpnWorkpaper $record): bool => (auth()->user()?->can('delete', $record) ?? false)
                        && $record->status === CkpnWorkpaper::STATUS_DRAFT),
            ]);
    }
}
