<?php

namespace App\Filament\Resources\GlToGlTransactions\Tables;

use App\Models\GlToGlTransaction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GlToGlTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purpose')->badge()->sortable(),
                TextColumn::make('reference_number')->searchable()->sortable(),
                TextColumn::make('receipt_number')->searchable()->sortable(),
                TextColumn::make('ckpnJournal.id')->label('Journal')->sortable(),
                TextColumn::make('ckpnWorkpaper.period')->label('Tanggal Cutoff Workpaper')->date()->sortable(),
                TextColumn::make('insuranceReceivable.loan_account_number')->label('Receivable loan account')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('resolution_status')->badge()->sortable(),
                TextColumn::make('response_code')->label('Response code')->sortable(),
                TextColumn::make('executor.name')->label('Executed by')->sortable(),
                TextColumn::make('executed_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('purpose')
                    ->options([
                        GlToGlTransaction::PURPOSE_CKPN_JOURNAL => 'CKPN journal',
                        GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP => 'Early termination repayment top up',
                        GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP => 'Early termination flat spread top up',
                        GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP => 'Early termination contract top up',
                        GlToGlTransaction::PURPOSE_RECEIVABLE_PAYMENT => 'Receivable payment',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        GlToGlTransaction::STATUS_PENDING => 'Pending',
                        GlToGlTransaction::STATUS_SUCCESS => 'Success',
                        GlToGlTransaction::STATUS_FAILED => 'Failed',
                        GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT => 'Unknown timeout',
                    ]),
                SelectFilter::make('resolution_status')
                    ->options([
                        GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED => 'No longer required',
                        GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED => 'Reconciliation required',
                        GlToGlTransaction::RESOLUTION_STATUS_RESOLVED_MANUALLY => 'Resolved manually',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
