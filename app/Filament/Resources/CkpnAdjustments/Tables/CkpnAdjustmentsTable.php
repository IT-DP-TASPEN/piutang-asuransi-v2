<?php

namespace App\Filament\Resources\CkpnAdjustments\Tables;

use App\Actions\CkpnAdjustment\CancelCkpnAdjustmentAction;
use App\Models\CkpnAdjustment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
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
                TextColumn::make('calculated_ckpn_amount')->money('IDR', 0, 'id_ID'),
                TextColumn::make('requested_adjusted_ckpn_rate')->numeric(4)->suffix('%'),
                TextColumn::make('requested_adjusted_ckpn_amount')->money('IDR', 0, 'id_ID'),
                TextColumn::make('approved_adjusted_ckpn_rate')->numeric(4)->suffix('%')->toggleable(),
                TextColumn::make('approved_adjusted_ckpn_amount')->money('IDR', 0, 'id_ID')->toggleable(),
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
                Action::make('cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn(CkpnAdjustment $record): bool => (auth()->user()?->can('cancel', $record) ?? false)
                        && in_array($record->status, [
                            CkpnAdjustment::STATUS_DRAFT,
                            CkpnAdjustment::STATUS_RETURNED,
                        ], true))
                    ->action(function (CkpnAdjustment $record): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            app(CancelCkpnAdjustmentAction::class)->handle($record, $user);
                        }

                        Notification::make()->success()->title('CKPN adjustment cancelled')->send();
                    }),
            ]);
    }
}
