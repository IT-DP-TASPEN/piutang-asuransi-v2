<?php

namespace App\Filament\Resources\ClaimDocumentTypes;

use App\Filament\Resources\ClaimDocumentTypes\Pages\CreateClaimDocumentType;
use App\Filament\Resources\ClaimDocumentTypes\Pages\EditClaimDocumentType;
use App\Filament\Resources\ClaimDocumentTypes\Pages\ListClaimDocumentTypes;
use App\Filament\Resources\ClaimDocumentTypes\Schemas\ClaimDocumentTypeForm;
use App\Filament\Resources\ClaimDocumentTypes\Tables\ClaimDocumentTypesTable;
use App\Models\ClaimDocumentType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClaimDocumentTypeResource extends Resource
{
    protected static ?string $model = ClaimDocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClaimDocumentTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimDocumentTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaimDocumentTypes::route('/'),
            'create' => CreateClaimDocumentType::route('/create'),
            'edit' => EditClaimDocumentType::route('/{record}/edit'),
        ];
    }
}
