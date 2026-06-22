<?php

namespace App\Filament\Resources\ClaimDocumentRequirements;

use App\Filament\Resources\ClaimDocumentRequirements\Pages\CreateClaimDocumentRequirement;
use App\Filament\Resources\ClaimDocumentRequirements\Pages\EditClaimDocumentRequirement;
use App\Filament\Resources\ClaimDocumentRequirements\Pages\ListClaimDocumentRequirements;
use App\Filament\Resources\ClaimDocumentRequirements\Schemas\ClaimDocumentRequirementForm;
use App\Filament\Resources\ClaimDocumentRequirements\Tables\ClaimDocumentRequirementsTable;
use App\Models\ClaimDocumentRequirement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClaimDocumentRequirementResource extends Resource
{
    protected static ?string $model = ClaimDocumentRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 26;

    public static function form(Schema $schema): Schema
    {
        return ClaimDocumentRequirementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimDocumentRequirementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaimDocumentRequirements::route('/'),
            'create' => CreateClaimDocumentRequirement::route('/create'),
            'edit' => EditClaimDocumentRequirement::route('/{record}/edit'),
        ];
    }
}
