<?php

namespace App\Filament\Resources\InsuranceReceivables;

use App\Filament\Resources\InsuranceReceivables\Pages\CreateInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\Pages\EditInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\Pages\ListInsuranceReceivables;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\ClaimStatusChangeRequestsRelationManager;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\InsuranceCoverLettersRelationManager;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\ReceivablePaymentRequestsRelationManager;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\StageLogsRelationManager;
use App\Filament\Resources\InsuranceReceivables\Schemas\InsuranceReceivableForm;
use App\Filament\Resources\InsuranceReceivables\Schemas\InsuranceReceivableInfolist;
use App\Filament\Resources\InsuranceReceivables\Tables\InsuranceReceivablesTable;
use App\Filament\Resources\RelationManagers\ReceivablePaymentsRelationManager;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InsuranceReceivableResource extends Resource
{
    protected static ?string $model = InsuranceReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'Insurance Receivables';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Insurance receivable';

    protected static ?string $pluralModelLabel = 'Insurance receivables';

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
        return InsuranceReceivableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InsuranceReceivableInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InsuranceReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StageLogsRelationManager::class,
            ReceivablePaymentsRelationManager::class,
            ReceivablePaymentRequestsRelationManager::class,
            DocumentsRelationManager::class,
            ClaimStatusChangeRequestsRelationManager::class,
            InsuranceCoverLettersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInsuranceReceivables::route('/'),
            'create' => CreateInsuranceReceivable::route('/create'),
            'view' => ViewInsuranceReceivable::route('/{record}'),
            'edit' => EditInsuranceReceivable::route('/{record}/edit'),
        ];
    }
}
