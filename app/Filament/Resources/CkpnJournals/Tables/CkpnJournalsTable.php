<?php

namespace App\Filament\Resources\CkpnJournals\Tables;

use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Models\CkpnJournal;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

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
                    ->label('Tanggal Cutoff Workpaper')
                    ->date()
                    ->sortable(),
                TextColumn::make('branchOffice.branch_name')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('IDR', 0, 'id_ID')
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
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CkpnJournal $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('submitSelectedJournals')
                        ->label('Submit Selected Journals')
                        ->requiresConfirmation()
                        ->modalDescription(fn (EloquentCollection $records): string => "You are about to submit {$records->count()} CKPN Journals for approval. Continue?")
                        ->visible(fn (): bool => auth()->user()?->can('Submit:CkpnJournal') ?? false)
                        ->action(function (EloquentCollection $records): void {
                            $user = auth()->user();

                            if (! $user instanceof User) {
                                abort(403);
                            }

                            try {
                                $submitted = app(SubmitCkpnJournalAction::class)->handleMany($records, $user);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->danger()
                                    ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title("{$submitted->count()} CKPN Journals have been submitted for approval.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
