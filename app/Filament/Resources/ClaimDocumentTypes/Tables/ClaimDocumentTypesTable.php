<?php

namespace App\Filament\Resources\ClaimDocumentTypes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimDocumentTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }
}
