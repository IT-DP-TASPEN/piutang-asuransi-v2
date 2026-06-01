<?php

namespace App\Filament\Resources\LegacyReceivables\Schemas;

use App\Models\LegacyReceivable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LegacyReceivableInfolist
{
    public static function configure(Schema $schema)
    {
        return $schema
            ->components([
                Section::make('Customer summary')
                    ->inlineLabel()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('customer_name')->label('Customer'),
                        TextEntry::make('cif')->label('CIF'),
                        TextEntry::make('loan_account_number')->label('Loan account'),
                        TextEntry::make('loan_alt_account_number')->label('Alt loan account'),
                        TextEntry::make('branchOffice.branch_name')->label('Branch'),
                        TextEntry::make('insuranceCompany.name')->label('Insurance company'),
                        TextEntry::make('claimStatus.name')->label('Claim status')->badge(),
                        TextEntry::make('date_of_death')->date(),
                        TextEntry::make('receivable_formation_date')->date(),
                        TextEntry::make('loan_outstanding')->numeric(2),
                        TextEntry::make('original_receivable_amount')->numeric(2),
                        TextEntry::make('remaining_receivable_amount')->numeric(2),
                        TextEntry::make('total_paid_amount')
                            ->label('Total paid')
                            ->state(fn (LegacyReceivable $record): string => (string) $record->payments()->sum('amount'))
                            ->numeric(2),
                    ]),
            ]);
    }
}
