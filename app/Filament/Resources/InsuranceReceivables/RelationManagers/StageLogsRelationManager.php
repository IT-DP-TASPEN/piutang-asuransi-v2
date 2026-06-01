<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->wrap()
                    ->limit(90)
                    ->searchable(),
                TextColumn::make('triggered_by_type')
                    ->label('Triggered by')
                    ->badge()
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('from_status')
                    ->label('From')
                    ->badge()
                    ->sortable(),
                TextColumn::make('to_status')
                    ->label('To')
                    ->badge()
                    ->sortable(),
                TextColumn::make('approval_request_id')
                    ->label('Approval #')
                    ->sortable(),
                TextColumn::make('api_integration_log_id')
                    ->label('API log #')
                    ->sortable(),
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
