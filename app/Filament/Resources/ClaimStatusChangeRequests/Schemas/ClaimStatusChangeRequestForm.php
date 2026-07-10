<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests\Schemas;

use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ClaimStatusChangeRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        Select::make('insurance_receivable_id')
                            ->label('Insurance receivable')
                            ->relationship('insuranceReceivable', 'loan_account_number')
                            ->getOptionLabelFromRecordUsing(fn (InsuranceReceivable $record): string => collect([
                                $record->branch_code,
                                $record->loan_account_number,
                                $record->customer_name,
                            ])->filter()->join(' - '))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?int $state): mixed => $set(
                                'from_claim_status_id',
                                InsuranceReceivable::query()->whereKey($state)->value('claim_status_id'),
                            ))
                            ->required(),
                        Select::make('from_claim_status_id')
                            ->label('Current claim status')
                            ->relationship('fromClaimStatus', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('to_claim_status_id')
                            ->label('Target claim status')
                            ->options(fn (): array => ClaimStatus::query()
                                ->where('is_active', true)
                                ->whereIn('code', ClaimStatus::DECISION_CODES)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Select::make('status')
                            ->disabled()
                            ->dehydrated(false)
                            ->options(ClaimStatusChangeRequest::statusOptions()),
                        Textarea::make('reason')
                            ->columnSpanFull()
                            ->maxLength(65535),
                        FileUpload::make('supporting_document_path')
                            ->label('Supporting document')
                            ->disk('public')
                            ->directory('claim-status-change-documents')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
