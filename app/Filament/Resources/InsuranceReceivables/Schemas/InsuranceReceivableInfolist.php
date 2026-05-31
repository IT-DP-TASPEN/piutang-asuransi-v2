<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class InsuranceReceivableInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('branch_code')
                    ->label('Branch'),
                TextEntry::make('loan_account_number')
                    ->label('Loan account'),
                TextEntry::make('customer_name')
                    ->label('Customer'),
                TextEntry::make('insuranceCompany.name')
                    ->label('Insurance company'),
                TextEntry::make('claimStatus.name')
                    ->label('Claim status'),
                TextEntry::make('loan_outstanding')
                    ->label('Loan outstanding')
                    ->numeric(2),
                TextEntry::make('receivable_amount')
                    ->label('Receivable amount')
                    ->numeric(2),
                TextEntry::make('workflow_status')
                    ->label('Workflow status')
                    ->badge(),
            ]);
    }
}
