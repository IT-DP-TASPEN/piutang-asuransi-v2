<?php

namespace App\Filament\Resources\CkpnJournals\Tables;

use App\Models\CkpnJournal;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CkpnJournalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('journal_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('ckpnWorkpaper.period')
                    ->label('Workpaper period')
                    ->date()
                    ->sortable(),
                TextColumn::make('branchOffice.branch_name')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
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
                    ->options(CkpnJournal::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
