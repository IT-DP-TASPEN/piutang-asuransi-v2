<?php

namespace App\Filament\Resources\LegacyReceivables;

use App\Filament\Resources\LegacyReceivables\Pages\CreateLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\Pages\EditLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\Pages\ListLegacyReceivables;
use App\Filament\Resources\LegacyReceivables\Pages\ViewLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\RelationManagers\LegacyReceivablePaymentsRelationManager;
use App\Filament\Resources\LegacyReceivables\Schemas\LegacyReceivableForm;
use App\Filament\Resources\LegacyReceivables\Schemas\LegacyReceivableInfolist;
use App\Filament\Resources\LegacyReceivables\Tables\LegacyReceivablesTable;
use App\Models\LegacyReceivable;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LegacyReceivableResource extends Resource
{
    protected static ?string $model = LegacyReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'Insurance Receivables';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Legacy receivable';

    protected static ?string $pluralModelLabel = 'Legacy receivables';

    protected static ?string $recordTitleAttribute = 'loan_account_number';

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
        return LegacyReceivableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LegacyReceivableInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LegacyReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LegacyReceivablePaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegacyReceivables::route('/'),
            'create' => CreateLegacyReceivable::route('/create'),
            'view' => ViewLegacyReceivable::route('/{record}'),
            'edit' => EditLegacyReceivable::route('/{record}/edit'),
        ];
    }
}
