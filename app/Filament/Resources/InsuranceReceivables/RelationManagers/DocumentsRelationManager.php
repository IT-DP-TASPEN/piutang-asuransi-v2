<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableDocument;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('document_type')
                    ->label('Document type')
                    ->required()
                    ->maxLength(255),
                FileUpload::make('file_path')
                    ->label('File')
                    ->disk(InsuranceReceivable::DOCUMENT_DISK)
                    ->directory('insurance-receivable-documents')
                    ->storeFileNamesIn('original_filename')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_type')
            ->columns([
                TextColumn::make('document_type')
                    ->label('Document type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('Original filename')
                    ->searchable(),
                TextColumn::make('uploader.name')
                    ->label('Uploaded by')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canMutateParentDocuments())
                    ->mutateDataUsing(function (array $data): array {
                        return [
                            ...$data,
                            'uploaded_by' => auth()->id(),
                        ];
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (InsuranceReceivableDocument $record): string => route('insurance-receivable-documents.download', $record))
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->visible(fn (InsuranceReceivableDocument $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->visible(fn (InsuranceReceivableDocument $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canMutateParentDocuments()),
                ]),
            ]);
    }

    private function canMutateParentDocuments(): bool
    {
        $user = auth()->user();
        $owner = $this->getOwnerRecord();

        if (! $user instanceof User || ! $owner instanceof InsuranceReceivable) {
            return false;
        }

        if (! ($user->can('Create:InsuranceReceivableDocument') ?? false)) {
            return false;
        }

        return ! $user->hasRole('branch_maker')
            || $user->can('update', $owner);
    }
}
