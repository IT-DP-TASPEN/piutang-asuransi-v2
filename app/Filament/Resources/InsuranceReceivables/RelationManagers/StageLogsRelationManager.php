<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableStageLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class StageLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'stageLogs';

    protected static ?string $title = 'Stage timeline';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event')
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('event')
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                    ->color(fn (?string $state): ?string => match (true) {
                        str($state)->contains(['failed', 'blocked', 'rejected', 'cancelled']) => 'danger',
                        str($state)->contains(['approved', 'completed', 'passed', 'executed', 'resolved', 'verified', 'confirmed', 'succeeded']) => 'success',
                        default => null,
                    })
                    ->weight(FontWeight::Medium)
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => str($record->description)->limit(80)->toString() ?: null)
                    ->wrap()
                    ->searchable(['event', 'description'])
                    ->sortable(),
                TextColumn::make('to_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => filled($record->from_status) && $record->from_status !== $record->to_status
                        ? 'from '.self::statusLabel($record->from_status)
                        : null)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('By')
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => $record->triggered_by_type ? Str::ucfirst($record->triggered_by_type) : null)
                    ->placeholder('System')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('approval_request_id')
                    ->label('Approval #')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('api_integration_log_id')
                    ->label('API log #')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->form([
                        TextInput::make('event')
                            ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('triggered_by_type')
                            ->label('Triggered by')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('from_status')
                            ->label('From status')
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('to_status')
                            ->label('To status')
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('approval_request_id')
                            ->label('Approval request')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('api_integration_log_id')
                            ->label('API log')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('description')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Textarea::make('metadata')
                            ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                                ? null
                                : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function eventLabel(?string $event): string
    {
        // `it_` events are IT-team steps, not the pronoun.
        return preg_replace('/^It\b/', 'IT', Str::ucfirst(str_replace('_', ' ', (string) $event)));
    }

    private static function statusLabel(?string $status): string
    {
        if (blank($status)) {
            return '-';
        }

        return InsuranceReceivable::workflowStatusOptions()[$status]
            ?? InsuranceReceivable::systemStatusOptions()[$status]
            ?? Str::ucfirst(str_replace('_', ' ', $status));
    }
}
