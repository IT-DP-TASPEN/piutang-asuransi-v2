<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\ReceivableFormationJournal;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class InsuranceReceivableInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Details')
                    ->columnSpanFull()
                    ->inlineLabel()
                    ->tabs([
                        Tabs\Tab::make('Summary')
                            ->columns(1)
                            ->schema([
                                TextEntry::make('branchOffice.branch_name')
                                    ->label('Branch')
                                    ->icon(Heroicon::OutlinedBuildingOffice),
                                TextEntry::make('loan_account_number')
                                    ->label('Loan account')
                                    ->icon(Heroicon::OutlinedIdentification),
                                TextEntry::make('customer_name')
                                    ->label('Customer')
                                    ->icon(Heroicon::OutlinedUser),
                                TextEntry::make('insuranceCompany.name')
                                    ->label('Insurance company')
                                    ->icon(Heroicon::OutlinedShieldCheck),
                                TextEntry::make('insuranceCompany.claim_type')
                                    ->label('Claim type')
                                    ->badge(),
                                TextEntry::make('date_of_death')
                                    ->label('Date of death')
                                    ->date()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('death_document_condition')
                                    ->label('Death document condition')
                                    ->formatStateUsing(fn(?string $state): string => InsuranceReceivable::deathDocumentConditionOptions()[$state] ?? '-'),
                                TextEntry::make('receivable_formation_date')
                                    ->label('Receivable formation date')
                                    ->date()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('claimStatus.name')
                                    ->label('Claim status')
                                    ->badge(),
                                TextEntry::make('workflow_status')
                                    ->label('Workflow status')
                                    ->formatStateUsing(fn(?string $state): string => InsuranceReceivable::workflowStatusOptions()[$state] ?? (string) $state)
                                    ->badge(),
                                TextEntry::make('system_status')
                                    ->label('System status')
                                    ->formatStateUsing(fn(?string $state): string => InsuranceReceivable::systemStatusOptions()[$state] ?? (string) $state)
                                    ->badge(),
                                TextEntry::make('last_error_message')
                                    ->label('Last error')
                                    ->visible(fn(InsuranceReceivable $record): bool => $record->last_error_message !== null),
                            ]),
                        Tabs\Tab::make('Loan snapshot')
                            ->columns(1)
                            ->schema([
                                TextEntry::make('loan_outstanding')
                                    ->label('Loan outstanding')
                                    ->money('IDR', 0, 'id_ID'),
                                TextEntry::make('receivable_amount')
                                    ->label('Receivable amount')
                                    ->money('IDR', 0, 'id_ID'),
                                TextEntry::make('remaining_receivable_amount')
                                    ->label('Remaining receivable')
                                    ->money('IDR', 0, 'id_ID'),
                                TextEntry::make('credit_limit')
                                    ->label('Credit limit')
                                    ->money('IDR', 0, 'id_ID'),
                                TextEntry::make('collectability')
                                    ->label('Collectability')
                                    ->badge(),
                                TextEntry::make('dpd')
                                    ->label('DPD')
                                    ->badge(),
                                TextEntry::make('product_name')
                                    ->label('Product')
                                    ->icon(Heroicon::OutlinedDocumentText),
                                TextEntry::make('saving_account_for_loan_repayment')
                                    ->label('Saving account for loan repayment')
                                    ->icon(Heroicon::OutlinedBanknotes),
                                TextEntry::make('start_period')
                                    ->date()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('end_period')
                                    ->date()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('inquiry_completed_at')
                                    ->dateTime()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('early_termination_executed_at')
                                    ->dateTime()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                            ]),
                        Tabs\Tab::make('Pending approval')
                            ->columnSpan(1)
                            ->visible(fn(InsuranceReceivable $record): bool => $record->approvalRequests()
                                ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                ->exists())
                            ->schema([
                                TextEntry::make('pending_approval')
                                    ->label('Request')
                                    ->state(fn(InsuranceReceivable $record): ?string => $record->approvalRequests()
                                        ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                        ->latest('id')
                                        ->first()?->workflow_code),
                            ]),
                        Tabs\Tab::make('Accounting validation')
                            ->columnSpan(1)
                            ->visible(fn(InsuranceReceivable $record): bool => $record->receivableFormationJournals()->exists()
                                && (auth()->user()?->can('view', $record) ?? false))
                            ->schema([
                                TextEntry::make('accounting_validation_journal_date')
                                    ->label('Journal date')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->journal_date?->toDateString())
                                    ->date()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('accounting_validation_amount')
                                    ->label('Amount')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->amount)
                                    ->money('IDR', 0, 'id_ID'),
                                TextEntry::make('accounting_validation_debit_account')
                                    ->label('Debit account')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->debit_account)
                                    ->icon(Heroicon::OutlinedBanknotes),
                                TextEntry::make('accounting_validation_credit_account')
                                    ->label('Credit account')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->credit_account)
                                    ->icon(Heroicon::OutlinedBanknotes),
                                TextEntry::make('accounting_validation_description')
                                    ->label('Description')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->description)
                                    ->columnSpanFull()
                                    ->icon(Heroicon::OutlinedDocumentText),
                                TextEntry::make('accounting_validation_notes')
                                    ->label('Notes')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->notes)
                                    ->columnSpanFull()
                                    ->icon(Heroicon::OutlinedDocumentText),
                                TextEntry::make('accounting_validation_submitter')
                                    ->label('Submitted by')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->submitter?->name)
                                    ->icon(Heroicon::OutlinedUser),
                                TextEntry::make('accounting_validation_submitted_at')
                                    ->label('Submitted at')
                                    ->state(fn(InsuranceReceivable $record): mixed => self::latestAccountingValidation($record)?->submitted_at)
                                    ->dateTime()
                                    ->icon(Heroicon::OutlinedCalendarDays),
                                TextEntry::make('accounting_validation_status')
                                    ->label('Status')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->status)
                                    ->badge(),
                            ]),
                        Tabs\Tab::make('Early termination top up')
                            ->columnSpan(1)
                            ->visible(fn(InsuranceReceivable $record): bool => self::latestBalanceInquiry($record) instanceof ApiIntegrationLog
                                || self::latestTopUp($record) instanceof GlToGlTransaction)
                            ->schema([
                                TextEntry::make('latest_balance_inquiry_available_balance')
                                    ->label('Latest available balance')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestBalanceInquiry($record)?->response_body['data']['availableBalance'] ?? null)
                                    ->money('IDR', 0, 'id_ID')
                                    ->placeholder('-'),
                                TextEntry::make('latest_balance_inquiry_response_code')
                                    ->label('Balance inquiry response code')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestBalanceInquiry($record)?->response_code)
                                    ->badge()
                                    ->placeholder('-'),
                                TextEntry::make('latest_balance_inquiry_description')
                                    ->label('Balance inquiry description')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestBalanceInquiry($record)?->response_description
                                        ?? self::latestBalanceInquiry($record)?->error_message)
                                    ->placeholder('-'),
                                TextEntry::make('latest_balance_inquiry_requested_at')
                                    ->label('Balance inquiry requested at')
                                    ->state(fn(InsuranceReceivable $record): mixed => self::latestBalanceInquiry($record)?->requested_at)
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('latest_top_up_reference')
                                    ->label('Top up reference')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestTopUp($record)?->reference_number)
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('latest_top_up_amount')
                                    ->label('Top up amount')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestTopUp($record)?->request_payload['amount'] ?? null)
                                    ->money('IDR', 0, 'id_ID')
                                    ->placeholder('-'),
                                TextEntry::make('latest_top_up_status')
                                    ->label('Top up status')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestTopUp($record)?->status)
                                    ->badge()
                                    ->placeholder('-'),
                                TextEntry::make('latest_top_up_response')
                                    ->label('Top up response')
                                    ->state(fn(InsuranceReceivable $record): ?string => self::latestTopUp($record)?->response_description)
                                    ->placeholder('-'),
                            ]),
                        Tabs\Tab::make('Pending claim status update')
                            ->columnSpan(1)
                            ->visible(fn(InsuranceReceivable $record): bool => $record->claimStatusChangeRequests()
                                ->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)
                                ->exists())
                            ->schema([
                                TextEntry::make('pending_claim_status_update')
                                    ->label('Request')
                                    ->state(function (InsuranceReceivable $record): ?string {
                                        $request = $record->claimStatusChangeRequests()
                                            ->with(['fromClaimStatus', 'toClaimStatus', 'requester'])
                                            ->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)
                                            ->latest('id')
                                            ->first();

                                        if (! $request instanceof ClaimStatusChangeRequest) {
                                            return null;
                                        }

                                        return collect([
                                            "From: {$request->fromClaimStatus?->name}",
                                            "To: {$request->toClaimStatus?->name}",
                                            "By: {$request->requester?->name}",
                                            $request->reason ? "Reason: {$request->reason}" : null,
                                        ])->filter()->join("\n");
                                    })
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function latestAccountingValidation(InsuranceReceivable $record): ?ReceivableFormationJournal
    {
        return $record->receivableFormationJournals()
            ->with('submitter')
            ->latest('id')
            ->first();
    }

    private static function latestBalanceInquiry(InsuranceReceivable $record): ?ApiIntegrationLog
    {
        return $record->apiIntegrationLogs()
            ->where('endpoint', '/account/balance')
            ->latest('id')
            ->first();
    }

    private static function latestTopUp(InsuranceReceivable $record): ?GlToGlTransaction
    {
        return $record->glToGlTransactions()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP)
            ->latest('id')
            ->first();
    }
}
