<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Actions\InsuranceReceivable\StoreClaimDocumentAction;
use App\Data\ClaimDocuments\ClaimDocumentChecklist;
use App\Data\ClaimDocuments\ClaimDocumentChecklistItem;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\ResolveClaimDocumentChecklist;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Claim document checklist';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof InsuranceReceivable
            && $ownerRecord->isWorkflowOrigin()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->checklist()->tableRecords())
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false)
            ->heading(fn (): string => $this->checklist()->progressLabel())
            ->description(fn (): string => implode(' • ', $this->checklist()->warnings))
            ->columns([
                TextColumn::make('name')
                    ->label('Document / data')
                    ->description(fn (array $record): ?string => $record['description']),
                TextColumn::make('conditional')
                    ->label('Requirement')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Conditional' : 'Required')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'primary'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        ClaimDocumentChecklistItem::STATUS_COMPLETE,
                        ClaimDocumentChecklistItem::STATUS_UPLOADED => 'success',
                        ClaimDocumentChecklistItem::STATUS_MISSING,
                        ClaimDocumentChecklistItem::STATUS_MISSING_DATA => 'danger',
                        ClaimDocumentChecklistItem::STATUS_PENDING_CONDITION => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('original_file_name')->label('File'),
                TextColumn::make('uploaded_by')->label('Uploaded by'),
                TextColumn::make('uploaded_at')->label('Uploaded at')->dateTime(),
            ])
            ->headerActions([
                Action::make('setDeathDocumentCondition')
                    ->label('Set death condition')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (): bool => $this->canMutateDocuments())
                    ->fillForm(fn (): array => [
                        'death_document_condition' => $this->getOwnerRecord()->death_document_condition,
                    ])
                    ->form([
                        Select::make('death_document_condition')
                            ->label('Death document condition')
                            ->options(InsuranceReceivable::deathDocumentConditionOptions())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->getOwnerRecord()->forceFill([
                            'death_document_condition' => $data['death_document_condition'],
                        ])->save();

                        $this->resetTable();
                        Notification::make()->success()->title('Death condition updated')->send();
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (array $record): bool => filled($record['uploaded_document_id']))
                    ->url(fn (array $record): string => route('insurance-receivable-documents.download', $record['uploaded_document_id']))
                    ->openUrlInNewTab(),
                Action::make('upload')
                    ->label(fn (array $record): string => filled($record['uploaded_document_id']) ? 'Replace' : 'Upload')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn (array $record): bool => $this->canUpload($record))
                    ->form([
                        FileUpload::make('file_path')
                            ->label('PDF file')
                            ->disk(InsuranceReceivable::DOCUMENT_DISK)
                            ->directory('insurance-receivable-documents')
                            ->acceptedFileTypes(['application/pdf'])
                            ->storeFileNamesIn('original_file_name')
                            ->required(),
                    ])
                    ->action(function (array $data, array $record): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(StoreClaimDocumentAction::class)->handle(
                            insuranceReceivable: $this->getOwnerRecord(),
                            claimDocumentTypeId: (int) $record['document_type_id'],
                            filePath: $this->singleFilePath($data['file_path']),
                            originalFileName: $this->singleFilePath($data['original_file_name'] ?? null),
                            user: $user,
                        );

                        $this->getOwnerRecord()->unsetRelation('documents');
                        $this->resetTable();
                        Notification::make()->success()->title('Claim document saved')->send();
                    }),
            ]);
    }

    private function checklist(): ClaimDocumentChecklist
    {
        $this->getOwnerRecord()->unsetRelation('documents');

        return app(ResolveClaimDocumentChecklist::class)->handle($this->getOwnerRecord());
    }

    /** @param array<string, mixed> $record */
    private function canUpload(array $record): bool
    {
        return $this->canMutateDocuments()
            && $record['uploadable']
            && in_array($record['status'], [
                ClaimDocumentChecklistItem::STATUS_MISSING,
                ClaimDocumentChecklistItem::STATUS_UPLOADED,
            ], true);
    }

    private function canMutateDocuments(): bool
    {
        $user = auth()->user();
        $owner = $this->getOwnerRecord();

        return $user instanceof User
            && $owner instanceof InsuranceReceivable
            && $owner->isWorkflowOrigin()
            && ($user->can('Create:InsuranceReceivableDocument') || $user->can('Update:InsuranceReceivableDocument'))
            && ($user->can('view', $owner) ?? false);
    }

    private function singleFilePath(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return (string) $value;
    }
}
