<?php

namespace App\Filament\Resources\ClaimDocumentTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClaimDocumentTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Claim document type')->schema([
                TextInput::make('code')->required()->unique(ignoreRecord: true)->rule('not_in:date_of_death'),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->columnSpanFull(),
                Select::make('accepted_file_types')
                    ->label('Accepted file types')
                    ->multiple()
                    ->options(['application/pdf' => 'PDF'])
                    ->default(['application/pdf'])
                    ->required(),
                TextInput::make('sort_order')->numeric()->default(0)->required(),
                Toggle::make('active')->default(true)->required(),
            ])->columns(2)->columnSpanFull(),
        ]);
    }
}
