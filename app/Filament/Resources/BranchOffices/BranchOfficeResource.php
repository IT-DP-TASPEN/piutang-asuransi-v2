<?php

namespace App\Filament\Resources\BranchOffices;

use App\Filament\Resources\BranchOffices\Pages\CreateBranchOffice;
use App\Filament\Resources\BranchOffices\Pages\EditBranchOffice;
use App\Filament\Resources\BranchOffices\Pages\ListBranchOffices;
use App\Filament\Resources\BranchOffices\Schemas\BranchOfficeForm;
use App\Filament\Resources\BranchOffices\Tables\BranchOfficesTable;
use App\Models\BranchOffice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BranchOfficeResource extends Resource
{
    protected static ?string $model = BranchOffice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Branch office';

    protected static ?string $pluralModelLabel = 'Branch offices';

    protected static ?string $recordTitleAttribute = 'branch_name';

    public static function form(Schema $schema): Schema
    {
        return BranchOfficeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchOfficesTable::configure($table);
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
            'index' => ListBranchOffices::route('/'),
            'create' => CreateBranchOffice::route('/create'),
            'edit' => EditBranchOffice::route('/{record}/edit'),
        ];
    }
}
