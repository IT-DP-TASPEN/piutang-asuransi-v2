<?php

namespace App\Filament\Resources\CkpnWorkpapers;

use App\Filament\Resources\CkpnWorkpapers\Pages\CreateCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\Pages\EditCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\Pages\ListCkpnWorkpapers;
use App\Filament\Resources\CkpnWorkpapers\Pages\ViewCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\RelationManagers\AdjustmentsRelationManager;
use App\Filament\Resources\CkpnWorkpapers\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\CkpnWorkpapers\Schemas\CkpnWorkpaperForm;
use App\Filament\Resources\CkpnWorkpapers\Schemas\CkpnWorkpaperInfolist;
use App\Filament\Resources\CkpnWorkpapers\Tables\CkpnWorkpapersTable;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CkpnWorkpaperResource extends Resource
{
    protected static ?string $model = CkpnWorkpaper::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'CKPN';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'CKPN workpaper';

    protected static ?string $pluralModelLabel = 'CKPN workpapers';

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
            return $query->where('branch_office_id', $user->branch_office_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return CkpnWorkpaperForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CkpnWorkpaperInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CkpnWorkpapersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            AdjustmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCkpnWorkpapers::route('/'),
            'create' => CreateCkpnWorkpaper::route('/create'),
            'view' => ViewCkpnWorkpaper::route('/{record}'),
            'edit' => EditCkpnWorkpaper::route('/{record}/edit'),
        ];
    }
}
