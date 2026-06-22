<?php

namespace App\Filament\Resources\InsuranceCompanies\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsuranceCompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Insurance company')
                ->inlineLabel()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('claim_type')->badge(),
                    TextEntry::make('legal_name'),
                    TextEntry::make('letter_recipient_name'),
                    TextEntry::make('letter_recipient_address')->columnSpanFull(),
                    TextEntry::make('ckpn_weight')->suffix('%'),
                    TextEntry::make('is_active')->label('Active')->badge(),
                ]),
        ]);
    }
}
