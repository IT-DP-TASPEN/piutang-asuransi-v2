<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests;

use App\Filament\Resources\ClaimStatusChangeRequests\Pages\CreateClaimStatusChangeRequest;
use App\Filament\Resources\ClaimStatusChangeRequests\Pages\EditClaimStatusChangeRequest;
use App\Filament\Resources\ClaimStatusChangeRequests\Pages\ListClaimStatusChangeRequests;
use App\Filament\Resources\ClaimStatusChangeRequests\Schemas\ClaimStatusChangeRequestForm;
use App\Filament\Resources\ClaimStatusChangeRequests\Tables\ClaimStatusChangeRequestsTable;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClaimStatusChangeRequestResource extends Resource
{
    protected static ?string $model = ClaimStatusChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Insurance Receivables';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Claim status change request';

    protected static ?string $pluralModelLabel = 'Claim status change requests';

    protected static ?string $recordTitleAttribute = 'id';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'auditor']) ?? false;
    }

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
            return $query->whereHas('insuranceReceivable', fn (Builder $query) => $query->where('branch_office_id', $user->branch_office_id));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return ClaimStatusChangeRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimStatusChangeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaimStatusChangeRequests::route('/'),
            'create' => CreateClaimStatusChangeRequest::route('/create'),
            'edit' => EditClaimStatusChangeRequest::route('/{record}/edit'),
        ];
    }
}
