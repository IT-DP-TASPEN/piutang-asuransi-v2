<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Actions\InsuranceCoverLetter\GenerateInsuranceCoverLetterAction;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceCoverLetter\InsuranceCoverLetterPreflight;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InsuranceCoverLettersRelationManager extends RelationManager
{
    protected static string $relationship = 'insuranceCoverLetters';

    protected static ?string $title = 'Cover letters';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof InsuranceReceivable
            && $ownerRecord->isWorkflowOrigin()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('letter_number')
            ->columns([
                TextColumn::make('letter_number')->label('Letter #')->searchable()->sortable(),
                TextColumn::make('claim_type')->badge()->sortable(),
                TextColumn::make('letter_date')->date()->sortable(),
                TextColumn::make('recipient_name')->label('Recipient'),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Created by'),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label('Generate / reuse letter')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (): bool => $this->getOwnerRecord() instanceof InsuranceReceivable
                        && $this->getOwnerRecord()->isWorkflowOrigin()
                        && (auth()->user()?->can('Generate:InsuranceCoverLetter') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => $this->generationDescription())
                    ->form([
                        DatePicker::make('letter_date')->default(now())->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        $letter = app(GenerateInsuranceCoverLetterAction::class)->handle(
                            $this->getOwnerRecord(),
                            $user,
                            $data['letter_date'],
                        );

                        if (blank($letter->generated_file_path)) {
                            Notification::make()
                                ->warning()
                                ->title('Letter generated; PDF unavailable')
                                ->body('Use HTML preview/print while PDF rendering is unavailable.')
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Immutable cover letter ready')->send();
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (InsuranceCoverLetter $record): string => route('insurance-cover-letters.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (InsuranceCoverLetter $record): bool => filled($record->generated_file_path))
                    ->url(fn (InsuranceCoverLetter $record): string => route('insurance-cover-letters.download', $record)),
            ]);
    }

    private function generationDescription(): string
    {
        $owner = $this->getOwnerRecord();
        $claimType = $owner->insuranceCompany?->claim_type;
        $existing = $owner->insuranceCoverLetters()->where('claim_type', $claimType)->first();

        if ($existing instanceof InsuranceCoverLetter) {
            return "Existing immutable letter {$existing->letter_number} will be reused.";
        }

        $warnings = app(InsuranceCoverLetterPreflight::class)->handle($owner)->warnings;

        return $warnings === []
            ? 'Checklist complete. A new immutable letter number will be generated.'
            : 'Warnings: '.implode(' • ', $warnings).' Generation remains allowed.';
    }
}
