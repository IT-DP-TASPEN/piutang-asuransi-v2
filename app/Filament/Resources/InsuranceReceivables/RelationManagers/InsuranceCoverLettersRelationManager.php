<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Actions\InsuranceCoverLetter\GenerateInsuranceCoverLetterDraftAction;
use App\Models\InsuranceCoverLetter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InsuranceCoverLettersRelationManager extends RelationManager
{
    protected static string $relationship = 'insuranceCoverLetters';

    protected static ?string $title = 'Cover letters';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('letter_number')
                    ->maxLength(255),
                DatePicker::make('letter_date'),
                Select::make('insurance_company_id')
                    ->label('Insurance company')
                    ->relationship('insuranceCompany', 'name')
                    ->default(fn (): int => $this->getOwnerRecord()->insurance_company_id)
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('recipient_name')
                    ->maxLength(255),
                TextInput::make('subject')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->columnSpanFull()
                    ->maxLength(65535),
                TextInput::make('generated_file_path')
                    ->label('Generated file path')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('letter_number')
                    ->label('Letter #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('letter_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('insuranceCompany.name')
                    ->label('Insurance company')
                    ->sortable(),
                TextColumn::make('subject')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('generateDraft')
                    ->label('Generate draft')
                    ->visible(fn (): bool => auth()->user()?->can('GenerateDraft:InsuranceCoverLetter') ?? false)
                    ->action(function (): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(GenerateInsuranceCoverLetterDraftAction::class)->handle($this->getOwnerRecord(), $user);

                        Notification::make()->success()->title('Cover letter draft generated')->send();
                    }),
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('Create:InsuranceCoverLetter') ?? false)
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'insurance_company_id' => $data['insurance_company_id'] ?? $this->getOwnerRecord()->insurance_company_id,
                        'status' => $data['status'] ?? InsuranceCoverLetter::STATUS_DRAFT,
                        'created_by' => $data['created_by'] ?? auth()->id(),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
