<?php

namespace App\Filament\Resources\ApiIntegrationLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiIntegrationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('service_name')
                    ->label('Service')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('endpoint')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('method')
                    ->sortable(),
                TextColumn::make('response_status')
                    ->label('HTTP')
                    ->sortable(),
                TextColumn::make('response_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('response_description')
                    ->label('Description')
                    ->searchable()
                    ->limit(40),
                IconColumn::make('is_success')
                    ->label('Success')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->sortable(),
                TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
