<?php

namespace App\Filament\Resources\LegacyReceivables;

use App\Filament\Resources\LegacyReceivables\Pages\CreateLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\Pages\EditLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\Pages\ListLegacyReceivables;
use App\Filament\Resources\LegacyReceivables\Pages\ViewLegacyReceivable;
use App\Filament\Resources\LegacyReceivables\RelationManagers\LegacyReceivablePaymentsRelationManager;
use App\Models\ClaimStatus;
use App\Models\LegacyReceivable;
use App\Models\User;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
        return $schema
            ->components([
                Section::make('Legacy receivable')
                    ->schema([
                        TextInput::make('cif')
                            ->label('CIF')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('customer_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('loan_account_number')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('loan_alt_account_number')
                            ->maxLength(255),
                        Select::make('branch_office_id')
                            ->relationship('branchOffice', 'branch_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('insurance_company_id')
                            ->relationship('insuranceCompany', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('claim_status_id')
                            ->relationship('claimStatus', 'name')
                            ->default(fn (): ?int => ClaimStatus::query()
                                ->where('code', ClaimStatus::DEFAULT_CODE)
                                ->value('id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('date_of_death')
                            ->required(),
                        DatePicker::make('receivable_formation_date'),
                        TextInput::make('loan_outstanding')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        TextInput::make('original_receivable_amount')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        TextInput::make('remaining_receivable_amount')
                            ->numeric()
                            ->step('0.01')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer summary')
                    ->schema([
                        TextEntry::make('customer_name')->label('Customer'),
                        TextEntry::make('cif')->label('CIF'),
                        TextEntry::make('loan_account_number')->label('Loan account'),
                        TextEntry::make('loan_alt_account_number')->label('Alt loan account'),
                        TextEntry::make('branchOffice.branch_name')->label('Branch'),
                        TextEntry::make('insuranceCompany.name')->label('Insurance company'),
                        TextEntry::make('claimStatus.name')->label('Claim status')->badge(),
                        TextEntry::make('date_of_death')->date(),
                        TextEntry::make('receivable_formation_date')->date(),
                        TextEntry::make('loan_outstanding')->numeric(2),
                        TextEntry::make('original_receivable_amount')->numeric(2),
                        TextEntry::make('remaining_receivable_amount')->numeric(2),
                        TextEntry::make('total_paid_amount')
                            ->label('Total paid')
                            ->state(fn (LegacyReceivable $record): string => (string) $record->payments()->sum('amount'))
                            ->numeric(2),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branchOffice.branch_code')->label('Branch')->searchable()->sortable(),
                TextColumn::make('cif')->label('CIF')->searchable()->sortable(),
                TextColumn::make('loan_account_number')->searchable()->sortable(),
                TextColumn::make('customer_name')->searchable()->sortable(),
                TextColumn::make('insuranceCompany.name')->label('Insurance')->searchable(),
                TextColumn::make('claimStatus.name')->label('Claim status')->badge(),
                TextColumn::make('original_receivable_amount')->numeric(2)->sortable(),
                TextColumn::make('remaining_receivable_amount')->numeric(2)->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_office_id')
                    ->label('Branch')
                    ->relationship('branchOffice', 'branch_name'),
                SelectFilter::make('insurance_company_id')
                    ->label('Insurance company')
                    ->relationship('insuranceCompany', 'name'),
                SelectFilter::make('claim_status_id')
                    ->label('Claim status')
                    ->relationship('claimStatus', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
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
