<?php

namespace App\Filament\Resources\ClaimDocumentRequirements\Schemas;

use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClaimDocumentRequirementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Claim document requirement')->schema([
                Select::make('claim_type')
                    ->options(InsuranceCompany::claimTypeOptions())
                    ->required(),
                Select::make('claim_document_type_id')
                    ->relationship('claimDocumentType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Toggle::make('is_required')->default(true)->required(),
                Toggle::make('is_conditional')->live()->default(false)->required(),
                Select::make('condition_key')
                    ->options(InsuranceReceivable::deathDocumentConditionOptions())
                    ->visible(fn ($get): bool => (bool) $get('is_conditional'))
                    ->required(fn ($get): bool => (bool) $get('is_conditional')),
                TextInput::make('sort_order')->numeric()->default(0)->required(),
            ])->columns(2)->columnSpanFull(),
        ]);
    }
}
