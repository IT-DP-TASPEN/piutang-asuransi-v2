<?php

namespace App\Filament\Resources\CkpnWorkpapers\RelationManagers;

use App\Actions\CkpnAdjustment\PrepareCkpnAdjustmentDataAction;
use App\Models\CkpnWorkpaperItem;
use App\Models\InsuranceReceivable;
use App\Models\LegacyReceivable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'CKPN items';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('loan_account_number')
            ->columns([
                TextColumn::make('source_label')->label('Source')->badge()->sortable(),
                TextColumn::make('branch_code')->label('Branch')->sortable(),
                TextColumn::make('branch_name')->label('Branch name')->toggleable(),
                TextColumn::make('cif_no')->label('CIF')->searchable(),
                TextColumn::make('loan_account_number')->label('Loan account')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('insurance_company_name')->label('Insurance'),
                TextColumn::make('claim_status_name')->label('Claim status')->badge(),
                TextColumn::make('age_days')->label('Age days')->sortable(),
                TextColumn::make('age_bucket_name')->label('Age bucket'),
                TextColumn::make('receivable_amount')->numeric(2)->sortable(),
                TextColumn::make('final_ckpn_rate')->label('CKPN rate')->numeric(4)->suffix('%')->sortable(),
                TextColumn::make('ckpn_amount')->label('CKPN amount')->numeric(2)->sortable(),
            ])
            ->filters([
                SelectFilter::make('receivable_type')
                    ->label('Source')
                    ->options([
                        InsuranceReceivable::class => 'Current',
                        LegacyReceivable::class => 'Legacy',
                    ]),
                SelectFilter::make('branch_code')
                    ->label('Branch')
                    ->options(fn (): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('branch_code')
                        ->distinct()
                        ->orderBy('branch_code')
                        ->pluck('branch_code', 'branch_code')
                        ->all()),
                SelectFilter::make('claim_status_name')
                    ->label('Claim status')
                    ->options(fn (): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('claim_status_name')
                        ->distinct()
                        ->orderBy('claim_status_name')
                        ->pluck('claim_status_name', 'claim_status_name')
                        ->all()),
                SelectFilter::make('insurance_company_name')
                    ->label('Insurance company')
                    ->options(fn (): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('insurance_company_name')
                        ->distinct()
                        ->orderBy('insurance_company_name')
                        ->pluck('insurance_company_name', 'insurance_company_name')
                        ->all()),
            ])
            ->recordActions([
                Action::make('createAdjustment')
                    ->label('Create adjustment')
                    ->visible(fn (): bool => auth()->user()?->can('Create:CkpnAdjustment') ?? false)
                    ->form([
                        TextInput::make('adjustment_type')
                            ->default('manual')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('adjusted_rate')
                            ->numeric()
                            ->step('0.0001'),
                        TextInput::make('adjusted_amount')
                            ->numeric()
                            ->step('0.01'),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->action(function (CkpnWorkpaperItem $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        $payload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
                            ...$data,
                            'ckpn_workpaper_item_id' => $record->id,
                        ], $user);

                        $record->adjustments()->create($payload);

                        Notification::make()->success()->title('CKPN adjustment draft created')->send();
                    }),
            ]);
    }
}
