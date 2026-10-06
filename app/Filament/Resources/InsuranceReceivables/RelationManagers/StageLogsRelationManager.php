<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Models\InsuranceReceivableStageLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->weight(FontWeight::Medium)
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => str($record->description)->limit(80)->toString() ?: null)
                    ->wrap()
                    ->searchable(['event', 'description'])
                    ->sortable(),
                TextColumn::make('to_status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => filled($record->from_status) && $record->from_status !== $record->to_status
                        ? "from {$record->from_status}"
                        : null)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('By')
                    ->description(fn (InsuranceReceivableStageLog $record): ?string => $record->triggered_by_type)
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
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('triggered_by_type')
                            ->label('Triggered by')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('from_status')
                            ->label('From status')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('to_status')
                            ->label('To status')
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
}
