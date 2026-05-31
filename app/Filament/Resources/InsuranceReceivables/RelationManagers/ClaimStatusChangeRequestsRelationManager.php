<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Actions\ClaimStatusChangeRequest\PrepareClaimStatusChangeRequestDataAction;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimStatusChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'claimStatusChangeRequests';

    protected static ?string $title = 'Claim status history';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_claim_status_id')
                    ->label('Current claim status')
                    ->relationship('fromClaimStatus', 'name')
                    ->default(fn (): int => $this->getOwnerRecord()->claim_status_id)
                    ->disabled()
                    ->dehydrated(false),
                Select::make('to_claim_status_id')
                    ->label('Target claim status')
                    ->options(fn (): array => ClaimStatus::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')
                    ->columnSpanFull()
                    ->maxLength(65535),
                FileUpload::make('supporting_document_path')
                    ->label('Supporting document')
                    ->disk('public')
                    ->directory('claim-status-change-documents')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')
                    ->label('Request #')
                    ->sortable(),
                TextColumn::make('fromClaimStatus.name')
                    ->label('From')
                    ->badge(),
                TextColumn::make('toClaimStatus.name')
                    ->label('To')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('Create:ClaimStatusChangeRequest') ?? false)
                    ->mutateDataUsing(function (array $data): array {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return $data;
                        }

                        return app(PrepareClaimStatusChangeRequestDataAction::class)->handle([
                            ...$data,
                            'insurance_receivable_id' => $this->getOwnerRecord()->id,
                        ], $user);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }
}
