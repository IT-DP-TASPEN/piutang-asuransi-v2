<?php

namespace App\Filament\Resources\GlToGlTransactions;

use App\Filament\Resources\GlToGlTransactions\Pages\ListGlToGlTransactions;
use App\Filament\Resources\GlToGlTransactions\Pages\ViewGlToGlTransaction;
use App\Filament\Resources\GlToGlTransactions\Schemas\GlToGlTransactionInfolist;
use App\Filament\Resources\GlToGlTransactions\Tables\GlToGlTransactionsTable;
use App\Models\GlToGlTransaction;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GlToGlTransactionResource extends Resource
{
    protected static ?string $model = GlToGlTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 80;

    protected static ?string $modelLabel = 'GL-to-GL transaction';

    protected static ?string $pluralModelLabel = 'GL-to-GL transactions';

    protected static ?string $recordTitleAttribute = 'reference_number';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User && self::canUseResource($user)
            ? $query
            : $query->whereRaw('1 = 0');
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::canUseResource($user);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::canUseResource($user);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GlToGlTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GlToGlTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGlToGlTransactions::route('/'),
            'view' => ViewGlToGlTransaction::route('/{record}'),
        ];
    }

    private static function canUseResource(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'auditor'])
            && $user->can('ViewAny:GlToGlTransaction');
    }
}
