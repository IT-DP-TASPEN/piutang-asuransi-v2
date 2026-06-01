<?php

namespace App\Filament\Resources\CkpnJournals;

use App\Filament\Resources\CkpnJournals\Pages\EditCkpnJournal;
use App\Filament\Resources\CkpnJournals\Pages\ListCkpnJournals;
use App\Filament\Resources\CkpnJournals\Schemas\CkpnJournalForm;
use App\Filament\Resources\CkpnJournals\Tables\CkpnJournalsTable;
use App\Models\CkpnJournal;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CkpnJournalResource extends Resource
{
    protected static ?string $model = CkpnJournal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|\UnitEnum|null $navigationGroup = 'CKPN';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'CKPN journal';

    protected static ?string $pluralModelLabel = 'CKPN journals';

    protected static ?string $recordTitleAttribute = 'id';

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
            return $query->where(fn (Builder $query) => $query
                ->where('branch_office_id', $user->branch_office_id)
                ->orWhereHas('ckpnWorkpaper', fn (Builder $query) => $query->where('branch_office_id', $user->branch_office_id)));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return CkpnJournalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CkpnJournalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCkpnJournals::route('/'),
            'edit' => EditCkpnJournal::route('/{record}/edit'),
        ];
    }
}
