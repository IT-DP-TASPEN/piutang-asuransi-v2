<?php

namespace App\Filament\Resources\ClaimStatuses;

use App\Filament\Resources\ClaimStatuses\Pages\CreateClaimStatus;
use App\Filament\Resources\ClaimStatuses\Pages\EditClaimStatus;
use App\Filament\Resources\ClaimStatuses\Pages\ListClaimStatuses;
use App\Filament\Resources\ClaimStatuses\Schemas\ClaimStatusForm;
use App\Filament\Resources\ClaimStatuses\Tables\ClaimStatusesTable;
use App\Models\ClaimStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClaimStatusResource extends Resource
{
    protected static ?string $model = ClaimStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Claim status';

    protected static ?string $pluralModelLabel = 'Claim statuses';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClaimStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimStatusesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaimStatuses::route('/'),
            'create' => CreateClaimStatus::route('/create'),
            'edit' => EditClaimStatus::route('/{record}/edit'),
        ];
    }
}
