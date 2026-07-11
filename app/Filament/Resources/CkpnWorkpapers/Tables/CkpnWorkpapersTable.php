<?php

namespace App\Filament\Resources\CkpnWorkpapers\Tables;

use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\SubmitCkpnWorkpaperAction;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

class CkpnWorkpapersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period')
                    ->label('Tanggal Cutoff')
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
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->money('IDR', 0, 'id_ID'),
                    )
                    ->sortable(),
                TextColumn::make('total_calculated_ckpn_amount')
                    ->label('Calculated CKPN')
                    ->money('IDR', 0, 'id_ID')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->money('IDR', 0, 'id_ID'),
                    )
                    ->sortable(),
                TextColumn::make('total_adjustment_delta')
                    ->label('Adjustment delta')
                    ->money('IDR', 0, 'id_ID')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->money('IDR', 0, 'id_ID'),
                    )
                    ->sortable(),
                TextColumn::make('total_effective_ckpn_amount')
                    ->label('Effective CKPN')
                    ->money('IDR', 0, 'id_ID')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->money('IDR', 0, 'id_ID'),
                    )
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
                SelectFilter::make('period')
                    ->label('Tanggal Cutoff')
                    ->options(
                        fn () => CkpnWorkpaper::query()
                            ->selectRaw('DATE(period) as period_date')
                            ->whereNotNull('period')
                            ->distinct()
                            ->orderByDesc('period_date')
                            ->pluck('period_date', 'period_date')
                            ->mapWithKeys(fn ($date) => [
                                $date => Carbon::parse($date)->translatedFormat('d M Y'),
                            ])
                            ->toArray()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $query, string $date) => $query->whereDate('period', $date),
                        );
                    })
                    ->searchable()
                    ->preload(),
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
                    ->visible(fn (CkpnWorkpaper $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && in_array($record->status, [
                            CkpnWorkpaper::STATUS_DRAFT,
                            CkpnWorkpaper::STATUS_RETURNED,
                        ], true)),
                DeleteAction::make()
                    ->visible(fn (CkpnWorkpaper $record): bool => (auth()->user()?->can('delete', $record) ?? false)
                        && $record->status === CkpnWorkpaper::STATUS_DRAFT),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('submitSelectedWorkpapers')
                        ->label('Submit Selected Workpapers')
                        ->requiresConfirmation()
                        ->modalDescription(fn (EloquentCollection $records): string => "You are about to submit {$records->count()} CKPN Workpapers. This will create workpaper approval requests. Continue?")
                        ->visible(fn (): bool => auth()->user()?->can('Submit:CkpnWorkpaper') ?? false)
                        ->action(function (EloquentCollection $records): void {
                            $user = auth()->user();

                            if (! $user instanceof User) {
                                abort(403);
                            }

                            try {
                                $submitted = app(SubmitCkpnWorkpaperAction::class)->handleMany($records, $user);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->danger()
                                    ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$submitted->count()} CKPN Workpapers have been submitted for approval.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('approveSelectedWorkpapers')
                        ->label('Approve Selected Workpapers')
                        ->requiresConfirmation()
                        ->modalDescription(fn (EloquentCollection $records): string => "You are about to approve {$records->count()} CKPN Workpapers. Continue?")
                        ->visible(fn (): bool => auth()->user()?->can('Approve:CkpnWorkpaper') ?? false)
                        ->action(function (EloquentCollection $records): void {
                            $user = auth()->user();

                            if (! $user instanceof User) {
                                abort(403);
                            }

                            try {
                                $approved = app(ApproveCkpnWorkpaperAction::class)->handleMany($records, $user);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->danger()
                                    ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$approved->count()} CKPN Workpapers have been approved.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('createJournalsForSelectedWorkpapers')
                        ->label('Create CKPN Journals')
                        ->requiresConfirmation()
                        ->modalDescription(fn (EloquentCollection $records): string => "You are about to create CKPN Journals for {$records->count()} approved CKPN Workpapers. Continue?")
                        ->visible(fn (): bool => auth()->user()?->can('CreateJournal:CkpnWorkpaper') ?? false)
                        ->action(function (EloquentCollection $records): void {
                            $user = auth()->user();

                            if (! $user instanceof User) {
                                abort(403);
                            }

                            try {
                                $journals = app(CreateCkpnJournalFromWorkpaperAction::class)->handleMany($records, $user);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->danger()
                                    ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$journals->count()} CKPN Journals have been created.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->groups([
                Group::make('period')
                    ->label('Tanggal Cutoff')
                    ->date()
                    ->collapsible(),
                Group::make('branchOffice.branch_name')
                    ->label('Branch')
                    ->collapsible(),
            ])
            // ->defaultGroup('period')
            ->groupsOnly();
    }
}
