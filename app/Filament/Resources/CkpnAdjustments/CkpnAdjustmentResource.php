<?php

namespace App\Filament\Resources\CkpnAdjustments;

use App\Filament\Resources\CkpnAdjustments\Pages\CreateCkpnAdjustment;
use App\Filament\Resources\CkpnAdjustments\Pages\EditCkpnAdjustment;
use App\Filament\Resources\CkpnAdjustments\Pages\ListCkpnAdjustments;
use App\Filament\Resources\CkpnAdjustments\Schemas\CkpnAdjustmentForm;
use App\Filament\Resources\CkpnAdjustments\Tables\CkpnAdjustmentsTable;
use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CkpnAdjustmentResource extends Resource
{
    protected static ?string $model = CkpnAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static string|\UnitEnum|null $navigationGroup = 'CKPN';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'CKPN adjustment';

    protected static ?string $pluralModelLabel = 'CKPN adjustments';

    protected static ?string $recordTitleAttribute = 'adjustment_type';

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
            return $query->whereHas('ckpnWorkpaper', fn (Builder $query) => $query->where('branch_office_id', $user->branch_office_id));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return CkpnAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CkpnAdjustmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCkpnAdjustments::route('/'),
            'create' => CreateCkpnAdjustment::route('/create'),
            'edit' => EditCkpnAdjustment::route('/{record}/edit'),
        ];
    }
}
