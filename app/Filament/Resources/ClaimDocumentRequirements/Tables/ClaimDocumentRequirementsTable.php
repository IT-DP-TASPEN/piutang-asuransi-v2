<?php

namespace App\Filament\Resources\ClaimDocumentRequirements\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimDocumentRequirementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claim_type')->badge()->sortable(),
                TextColumn::make('claimDocumentType.name')->label('Document')->searchable()->sortable(),
                IconColumn::make('is_required')->boolean(),
                IconColumn::make('is_conditional')->boolean(),
                TextColumn::make('condition_key')->badge(),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('claim_type')
            ->recordActions([EditAction::make()]);
    }
}
