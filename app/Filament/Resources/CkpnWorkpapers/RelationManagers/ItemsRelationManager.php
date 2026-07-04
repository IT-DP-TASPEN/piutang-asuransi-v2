<?php

namespace App\Filament\Resources\CkpnWorkpapers\RelationManagers;

use App\Actions\CkpnAdjustment\PrepareCkpnAdjustmentDataAction;
use App\Actions\CkpnAdjustment\SubmitCkpnAdjustmentAction;
use App\Actions\GeneratedExport\GenerateCkpnWorkpaperItemsExportAction;
use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\GeneratedExport;
use App\Models\InsuranceReceivable;
use App\Models\LegacyReceivable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

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
                TextColumn::make('receivable_amount')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('calculated_ckpn_rate')->label('Calculated rate')->numeric(4)->suffix('%')->sortable(),
                TextColumn::make('calculated_ckpn_amount')->label('Calculated CKPN')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('adjusted_ckpn_rate')->label('Adjusted rate')->numeric(4)->suffix('%')->placeholder('-')->sortable(),
                TextColumn::make('adjusted_ckpn_amount')->label('Adjusted CKPN')->money('IDR', 0, 'id_ID')->placeholder('-')->sortable(),
                TextColumn::make('effective_ckpn_rate')->label('Effective rate')->numeric(4)->suffix('%')->sortable(),
                TextColumn::make('effective_ckpn_amount')->label('Effective CKPN')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('adjustment_applied_at')->label('Adjusted at')->dateTime()->placeholder('-')->toggleable(),
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
                    ->options(fn(): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('branch_code')
                        ->distinct()
                        ->orderBy('branch_code')
                        ->pluck('branch_code', 'branch_code')
                        ->all()),
                SelectFilter::make('claim_status_name')
                    ->label('Claim status')
                    ->options(fn(): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('claim_status_name')
                        ->distinct()
                        ->orderBy('claim_status_name')
                        ->pluck('claim_status_name', 'claim_status_name')
                        ->all()),
                SelectFilter::make('insurance_company_name')
                    ->label('Insurance company')
                    ->options(fn(): array => CkpnWorkpaperItem::query()
                        ->whereNotNull('insurance_company_name')
                        ->distinct()
                        ->orderBy('insurance_company_name')
                        ->pluck('insurance_company_name', 'insurance_company_name')
                        ->all()),
            ])
            ->headerActions([
                $this->exportCkpnItemsAction(),
            ])
            ->recordActions([
                Action::make('createAdjustment')
                    ->label('Request CKPN Adjustment')
                    ->visible(fn(): bool => auth()->user()?->can('Create:CkpnAdjustment') ?? false)
                    ->form([
                        TextInput::make('adjustment_type')
                            ->default(CkpnAdjustment::TYPE_OVERRIDE_FINAL_CKPN_AMOUNT)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('calculated_ckpn_amount')
                            ->default(fn(CkpnWorkpaperItem $record): string => $record->calculated_ckpn_amount)
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->step('0.01'),
                        TextInput::make('effective_ckpn_amount')
                            ->default(fn(CkpnWorkpaperItem $record): string => $record->effective_ckpn_amount)
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->step('0.01'),
                        TextInput::make('requested_adjusted_ckpn_amount')
                            ->label('Requested final CKPN amount')
                            ->numeric()
                            ->required()
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

                        $adjustment = $record->adjustments()->create($payload);
                        app(SubmitCkpnAdjustmentAction::class)->handle($adjustment, $user);

                        Notification::make()->success()->title('CKPN adjustment submitted')->send();
                    }),
            ]);
    }

    protected function exportCkpnItemsAction(): Action
    {
        return Action::make('exportCkpnItems')
            ->label('Export CKPN Items')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn(): bool => $this->canExportCkpnItems())
            ->disabled(fn(): bool => ! $this->hasItemsToExport())
            ->tooltip(fn(): ?string => $this->hasItemsToExport()
                ? null
                : 'CKPN workpaper has no generated items to export.')
            ->action(function (): void {
                $user = auth()->user();
                $workpaper = $this->workpaper();

                if (! $user instanceof User || ! $workpaper instanceof CkpnWorkpaper) {
                    return;
                }

                try {
                    $export = app(GenerateCkpnWorkpaperItemsExportAction::class)->handle($workpaper, $user);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($this->validationMessage($exception))
                        ->send();

                    return;
                }

                if ($export->status === GeneratedExport::STATUS_FAILED) {
                    Notification::make()
                        ->danger()
                        ->title('CKPN items export failed')
                        ->body($export->metadata['error'] ?? null)
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('CKPN items XLSX generated')
                    ->actions([
                        Action::make('download')
                            ->label('Download')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->url(route('generated-exports.download', $export), true),
                    ])
                    ->send();
            });
    }

    protected function canExportCkpnItems(): bool
    {
        $workpaper = $this->workpaper();

        return $workpaper instanceof CkpnWorkpaper
            && (auth()->user()?->can('generateExport', $workpaper) ?? false);
    }

    protected function hasItemsToExport(): bool
    {
        return $this->workpaper()?->items()->exists() ?? false;
    }

    protected function workpaper(): ?CkpnWorkpaper
    {
        $record = $this->getOwnerRecord();

        return $record instanceof CkpnWorkpaper ? $record : null;
    }

    protected function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->first() ?: $exception->getMessage();
    }
}
