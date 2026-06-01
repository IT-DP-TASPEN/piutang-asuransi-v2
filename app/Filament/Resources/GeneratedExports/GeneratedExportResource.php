<?php

namespace App\Filament\Resources\GeneratedExports;

use App\Filament\Resources\GeneratedExports\Pages\ListGeneratedExports;
use App\Filament\Resources\GeneratedExports\Pages\ViewGeneratedExport;
use App\Filament\Resources\GeneratedExports\Schemas\GeneratedExportInfolist;
use App\Filament\Resources\GeneratedExports\Tables\GeneratedExportsTable;
use App\Models\CkpnWorkpaper;
use App\Models\GeneratedExport;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GeneratedExportResource extends Resource
{
    protected static ?string $model = GeneratedExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Generated export';

    protected static ?string $pluralModelLabel = 'Generated exports';

    protected static ?string $recordTitleAttribute = 'file_path';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (RoleScope::canViewAllBranches($user)) {
            return $query;
        }

        if (RoleScope::isBranchScoped($user)) {
            return $query->whereHasMorph(
                'exportable',
                [CkpnWorkpaper::class],
                fn (Builder $query) => $query->where('branch_office_id', $user->branch_office_id),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    public static function infolist(Schema $schema): Schema
    {
        return GeneratedExportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeneratedExportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGeneratedExports::route('/'),
            'view' => ViewGeneratedExport::route('/{record}'),
        ];
    }
}
