<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class InsuranceReceivableInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Details')
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('Summary')
                            ->schema([
                                Section::make('Status')
                                    ->compact()
                                    ->columns(4)
                                    ->schema([
                                        TextEntry::make('claimStatus.name')
                                            ->label('Claim status')
                                            ->badge(),
                                        TextEntry::make('workflow_status')
                                            ->label('Workflow status')
                                            ->formatStateUsing(fn (?string $state): string => InsuranceReceivable::workflowStatusOptions()[$state] ?? (string) $state)
                                            ->badge(),
                                        TextEntry::make('system_status')
                                            ->label('System status')
                                            ->formatStateUsing(fn (?string $state): string => InsuranceReceivable::systemStatusOptions()[$state] ?? (string) $state)
                                            ->badge(),
                                        TextEntry::make('origin_type')
                                            ->label('Origin')
                                            ->formatStateUsing(fn (?string $state): string => InsuranceReceivable::originTypeOptions()[$state] ?? (string) $state)
                                            ->badge(),
                                        TextEntry::make('last_error_message')
                                            ->label('Last error')
                                            ->color('danger')
                                            ->columnSpanFull()
                                            ->visible(fn (InsuranceReceivable $record): bool => $record->last_error_message !== null),
                                    ]),
                                Section::make('Debtor')
                                    ->compact()
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('customer_name')
                                            ->label('Customer')
                                            ->weight(FontWeight::SemiBold)
                                            ->icon(Heroicon::OutlinedUser),
                                        TextEntry::make('loan_account_number')
                                            ->label('Loan account')
                                            ->copyable()
                                            ->icon(Heroicon::OutlinedIdentification),
                                        TextEntry::make('branchOffice.branch_name')
                                            ->label('Branch')
                                            ->icon(Heroicon::OutlinedBuildingOffice),
                                        TextEntry::make('receivable_formation_date')
                                            ->label('Receivable formation date')
                                            ->date()
                                            ->icon(Heroicon::OutlinedCalendarDays),
                                    ]),
                                Section::make('Claim')
                                    ->compact()
                                    ->columns(2)
                                    ->schema([
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
                                            ->formatStateUsing(fn (?string $state): string => InsuranceReceivable::deathDocumentConditionOptions()[$state] ?? '-'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Loan snapshot')
                            ->schema([
                                Section::make('Amounts')
                                    ->compact()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('receivable_amount')
                                            ->label('Receivable amount')
                                            ->money('IDR', 0, 'id_ID')
                                            ->size(TextSize::Large)
                                            ->weight(FontWeight::Bold),
                                        TextEntry::make('remaining_receivable_amount')
                                            ->label('Remaining receivable')
                                            ->money('IDR', 0, 'id_ID')
                                            ->size(TextSize::Large)
                                            ->weight(FontWeight::Bold),
                                        TextEntry::make('credit_limit')
                                            ->label('Credit limit')
                                            ->money('IDR', 0, 'id_ID'),
                                        TextEntry::make('loan_outstanding')
                                            ->label('Fincloud outstanding')
                                            ->money('IDR', 0, 'id_ID'),
                                        TextEntry::make('contract_outstanding_amount')
                                            ->label('Contract outstanding')
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('contract_outstanding_spread')
                                            ->label('Spread')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::contractSpread($record))
                                            ->money('IDR', 0, 'id_ID'),
                                    ]),
                                Section::make('Loan')
                                    ->compact()
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('product_name')
                                            ->label('Product')
                                            ->icon(Heroicon::OutlinedDocumentText),
                                        TextEntry::make('saving_account_for_loan_repayment')
                                            ->label('Saving account for loan repayment')
                                            ->copyable()
                                            ->icon(Heroicon::OutlinedBanknotes),
                                        TextEntry::make('collectability')
                                            ->label('Collectability')
                                            ->badge(),
                                        TextEntry::make('dpd')
                                            ->label('DPD')
                                            ->badge(),
                                        TextEntry::make('start_period')
                                            ->date()
                                            ->icon(Heroicon::OutlinedCalendarDays),
                                        TextEntry::make('end_period')
                                            ->date()
                                            ->icon(Heroicon::OutlinedCalendarDays),
                                    ]),
                                Section::make('Contract inquiry')
                                    ->compact()
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('contract_outstanding_requested_as_of')
                                            ->label('Contract requested AsOf')
                                            ->date()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_outstanding_as_of')
                                            ->label('Contract returned AsOf')
                                            ->date()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_outstanding_product_code')
                                            ->label('Contract product code')
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_outstanding_trx_type')
                                            ->label('LSA trx type')
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('inquiry_completed_at')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('early_termination_executed_at')
                                            ->dateTime()
                                            ->placeholder('-'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Pending approval')
                            ->visible(fn (InsuranceReceivable $record): bool => $record->approvalRequests()
                                ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                ->exists())
                            ->schema([
                                TextEntry::make('pending_approval')
                                    ->label('Request')
                                    ->badge()
                                    ->state(fn (InsuranceReceivable $record): ?string => $record->approvalRequests()
                                        ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                        ->latest('id')
                                        ->first()?->workflow_code),
                            ]),
                        Tabs\Tab::make('Installment repayment')
                            ->visible(fn (InsuranceReceivable $record): bool => $record->installmentRepayment()->exists()
                                && (auth()->user()?->can('view', $record) ?? false))
                            ->schema([
                                Section::make('Repayment')
                                    ->compact()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('installment_repayment_status')
                                            ->label('Status')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->status)
                                            ->badge(),
                                        TextEntry::make('installment_repayment_amount')
                                            ->label('Installment amount')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->installment_amount)
                                            ->money('IDR', 0, 'id_ID')
                                            ->size(TextSize::Large)
                                            ->weight(FontWeight::Bold),
                                        TextEntry::make('installment_repayment_next_due_date')
                                            ->label('Next due date')
                                            ->state(fn (InsuranceReceivable $record): mixed => self::installmentRepayment($record)?->next_due_date)
                                            ->date()
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_before')
                                            ->label('Loan outstanding before')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->loan_outstanding_before)
                                            ->money('IDR', 0, 'id_ID'),
                                        TextEntry::make('installment_repayment_after')
                                            ->label('Loan outstanding after')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->loan_outstanding_after)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_saving_account')
                                            ->label('Saving account')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->saving_account_number)
                                            ->icon(Heroicon::OutlinedBanknotes)
                                            ->copyable()
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Latest result')
                                    ->compact()
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('installment_repayment_reference')
                                            ->label('Reference number')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->reference_number)
                                            ->copyable()
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_response')
                                            ->label('Response')
                                            ->state(fn (InsuranceReceivable $record): ?string => collect([
                                                self::installmentRepayment($record)?->response_code,
                                                self::installmentRepayment($record)?->response_description,
                                            ])->filter()->join(' - '))
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_executed_at')
                                            ->label('Executed at')
                                            ->state(fn (InsuranceReceivable $record): mixed => self::installmentRepayment($record)?->executed_at)
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_resolved_at')
                                            ->label('Resolved at')
                                            ->state(fn (InsuranceReceivable $record): mixed => self::installmentRepayment($record)?->resolved_at)
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_last_error')
                                            ->label('Last error')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::installmentRepayment($record)?->last_error_message)
                                            ->color('danger')
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                        TextEntry::make('installment_repayment_attempts')
                                            ->label('Attempts')
                                            ->state(fn (InsuranceReceivable $record): array => self::installmentAttemptHistory($record))
                                            ->listWithLineBreaks()
                                            ->limitList(1)
                                            ->expandableLimitedList()
                                            ->fontFamily(FontFamily::Mono)
                                            ->size(TextSize::ExtraSmall)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Early termination top up')
                            ->visible(fn (InsuranceReceivable $record): bool => self::latestCalculationInquiry($record) instanceof EarlyTerminationBalanceInquiry
                                || self::latestBalanceInquiry($record) instanceof EarlyTerminationBalanceInquiry
                                || self::latestPostVerificationInquiry($record) instanceof EarlyTerminationBalanceInquiry
                                || self::latestTopUp($record) instanceof GlToGlTransaction
                                || self::latestFlatSpreadTopUp($record) instanceof GlToGlTransaction
                                || self::latestContractTopUp($record) instanceof GlToGlTransaction)
                            ->schema([
                                Section::make('Funding requirement')
                                    ->compact()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('latest_total_funding_amount')
                                            ->label('Required total funding')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestCalculationInquiry($record)?->total_funding_amount)
                                            ->money('IDR', 0, 'id_ID')
                                            ->size(TextSize::Large)
                                            ->weight(FontWeight::Bold)
                                            ->placeholder('-'),
                                        TextEntry::make('latest_lsa_top_up_amount')
                                            ->label('Current LSA required')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestCalculationInquiry($record)?->lsa_top_up_amount)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('latest_piutang_top_up_amount')
                                            ->label('Current Piutang required')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestCalculationInquiry($record)?->piutang_top_up_amount)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_available_balance')
                                            ->label('Pre-funding OPER balance')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->available_balance)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_balance_shortfall_amount')
                                            ->label('OPER balance shortfall (verification only)')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->balance_shortfall_amount)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('post_verification_available_balance')
                                            ->label('Final verification balance')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestPostVerificationInquiry($record)?->available_balance)
                                            ->money('IDR', 0, 'id_ID')
                                            ->placeholder('-'),
                                        TextEntry::make('top_up_resolution')
                                            ->label('Resolution')
                                            ->state(fn (InsuranceReceivable $record): ?string => collect([
                                                self::latestFlatSpreadTopUp($record)?->resolution_status,
                                                self::latestContractTopUp($record)?->resolution_status,
                                            ])->filter()->join(' / '))
                                            ->placeholder('-'),
                                    ]),
                                Section::make('LSA top up')
                                    ->compact()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('flat_spread_top_up_status')
                                            ->label('Status')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestFlatSpreadTopUp($record)?->status ?? self::latestFlatSpreadTopUp($record)?->resolution_status)
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('flat_spread_top_up_amount')
                                            ->label('Amount')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestFlatSpreadTopUp($record)?->request_payload['amount'] ?? null)
                                            ->money('IDR', 0, 'id_ID')
                                            ->weight(FontWeight::SemiBold)
                                            ->placeholder('-'),
                                        TextEntry::make('flat_spread_top_up_reference')
                                            ->label('Reference')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestFlatSpreadTopUp($record)?->reference_number)
                                            ->copyable()
                                            ->placeholder('-'),
                                        TextEntry::make('flat_spread_top_up_description')
                                            ->label('Description')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestFlatSpreadTopUp($record)?->response_description
                                                ?? self::latestFlatSpreadTopUp($record)?->resolution_reason)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                        TextEntry::make('flat_spread_top_up_attempts')
                                            ->label('Attempts')
                                            ->state(fn (InsuranceReceivable $record): array => self::glAttemptHistory($record, GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP))
                                            ->listWithLineBreaks()
                                            ->limitList(1)
                                            ->expandableLimitedList()
                                            ->fontFamily(FontFamily::Mono)
                                            ->size(TextSize::ExtraSmall)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Piutang top up')
                                    ->compact()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('contract_top_up_status')
                                            ->label('Status')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestContractOrLegacyTopUp($record)?->status ?? self::latestContractOrLegacyTopUp($record)?->resolution_status)
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_top_up_amount')
                                            ->label('Amount')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestContractOrLegacyTopUp($record)?->request_payload['amount'] ?? null)
                                            ->money('IDR', 0, 'id_ID')
                                            ->weight(FontWeight::SemiBold)
                                            ->placeholder('-'),
                                        TextEntry::make('contract_top_up_reference')
                                            ->label('Reference')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestContractOrLegacyTopUp($record)?->reference_number)
                                            ->copyable()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_top_up_description')
                                            ->label('Description')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::latestContractOrLegacyTopUp($record)?->response_description
                                                ?? self::latestContractOrLegacyTopUp($record)?->resolution_reason)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                        TextEntry::make('contract_top_up_attempts')
                                            ->label('Attempts')
                                            ->state(fn (InsuranceReceivable $record): array => self::glAttemptHistory($record, GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP))
                                            ->listWithLineBreaks()
                                            ->limitList(1)
                                            ->expandableLimitedList()
                                            ->fontFamily(FontFamily::Mono)
                                            ->size(TextSize::ExtraSmall)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Balance inquiry')
                                    ->compact()
                                    ->collapsible()
                                    ->collapsed()
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('latest_balance_inquiry_status')
                                            ->label('Status')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->status)
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_response_code')
                                            ->label('Response code')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->response_code)
                                            ->badge()
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_requested_at')
                                            ->label('Requested at')
                                            ->state(fn (InsuranceReceivable $record): mixed => self::calculationOrLegacyInquiry($record)?->requested_at)
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_saving_account')
                                            ->label('Saving account')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->saving_account_number)
                                            ->copyable()
                                            ->placeholder('-'),
                                        TextEntry::make('latest_balance_inquiry_description')
                                            ->label('Description')
                                            ->state(fn (InsuranceReceivable $record): ?string => self::calculationOrLegacyInquiry($record)?->response_description
                                                ?? self::calculationOrLegacyInquiry($record)?->error_message)
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Pending claim status update')
                            ->visible(fn (InsuranceReceivable $record): bool => $record->claimStatusChangeRequests()
                                ->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)
                                ->exists())
                            ->schema([
                                TextEntry::make('pending_claim_status_update')
                                    ->label('Request')
                                    ->state(function (InsuranceReceivable $record): ?array {
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
                                        ])->filter()->values()->all();
                                    })
                                    ->listWithLineBreaks()
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function installmentRepayment(InsuranceReceivable $record): ?InsuranceReceivableInstallmentRepayment
    {
        $record->loadMissing('installmentRepayment');

        $repayment = $record->getRelation('installmentRepayment');

        return $repayment instanceof InsuranceReceivableInstallmentRepayment ? $repayment : null;
    }

    /** @return list<string> */
    private static function installmentAttemptHistory(InsuranceReceivable $record): array
    {
        $repayment = self::installmentRepayment($record);

        if (! $repayment instanceof InsuranceReceivableInstallmentRepayment) {
            return [];
        }

        return $repayment->attempts()
            ->latest('attempt_no')
            ->get()
            ->map(fn ($attempt): string => collect([
                '#'.str_pad((string) $attempt->attempt_no, 3, '0', STR_PAD_LEFT),
                $attempt->reference_number,
                $attempt->status,
                $attempt->response_code,
                $attempt->response_description,
                $attempt->executed_at?->toDateTimeString(),
            ])->filter()->join(' | '))
            ->all();
    }

    /** @return list<string> */
    private static function glAttemptHistory(InsuranceReceivable $record, string $purpose): array
    {
        return $record->glToGlTransactions()
            ->where('purpose', $purpose)
            ->latest('attempt_no')
            ->latest('id')
            ->get()
            ->map(fn (GlToGlTransaction $attempt): string => collect([
                '#'.str_pad((string) $attempt->attempt_no, 3, '0', STR_PAD_LEFT),
                $attempt->reference_number,
                $attempt->status,
                $attempt->resolution_status,
                $attempt->resolution_outcome,
                $attempt->response_code,
                $attempt->response_description,
                $attempt->resolution_notes,
                $attempt->resolver?->name,
                $attempt->resolved_at?->toDateTimeString(),
                $attempt->executed_at?->toDateTimeString(),
            ])->filter()->join(' | '))
            ->all();
    }

    private static function latestBalanceInquiry(InsuranceReceivable $record): ?EarlyTerminationBalanceInquiry
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $inquiry = $record->getRelation('latestEarlyTerminationBalanceInquiry');

        return $inquiry instanceof EarlyTerminationBalanceInquiry ? $inquiry : null;
    }

    private static function latestCalculationInquiry(InsuranceReceivable $record): ?EarlyTerminationBalanceInquiry
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $inquiry = $record->getRelation('latestCalculationInquiry');

        return $inquiry instanceof EarlyTerminationBalanceInquiry ? $inquiry : null;
    }

    private static function latestPostVerificationInquiry(InsuranceReceivable $record): ?EarlyTerminationBalanceInquiry
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $inquiry = $record->getRelation('latestPostTopUpVerificationInquiry');

        return $inquiry instanceof EarlyTerminationBalanceInquiry ? $inquiry : null;
    }

    private static function calculationOrLegacyInquiry(InsuranceReceivable $record): ?EarlyTerminationBalanceInquiry
    {
        return self::latestCalculationInquiry($record) ?? self::latestBalanceInquiry($record);
    }

    private static function latestTopUp(InsuranceReceivable $record): ?GlToGlTransaction
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $topUp = $record->getRelation('latestEarlyTerminationTopUpTransaction');

        return $topUp instanceof GlToGlTransaction ? $topUp : null;
    }

    private static function latestFlatSpreadTopUp(InsuranceReceivable $record): ?GlToGlTransaction
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $topUp = $record->getRelation('latestEarlyTerminationFlatSpreadTopUpTransaction');

        return $topUp instanceof GlToGlTransaction ? $topUp : null;
    }

    private static function latestContractTopUp(InsuranceReceivable $record): ?GlToGlTransaction
    {
        self::loadEarlyTerminationTopUpRelations($record);

        $topUp = $record->getRelation('latestEarlyTerminationContractTopUpTransaction');

        return $topUp instanceof GlToGlTransaction ? $topUp : null;
    }

    private static function latestContractOrLegacyTopUp(InsuranceReceivable $record): ?GlToGlTransaction
    {
        return self::latestContractTopUp($record) ?? self::latestTopUp($record);
    }

    private static function loadEarlyTerminationTopUpRelations(InsuranceReceivable $record): void
    {
        $record->loadMissing([
            'latestEarlyTerminationBalanceInquiry',
            'latestCalculationInquiry',
            'latestPostTopUpVerificationInquiry',
            'latestEarlyTerminationTopUpTransaction',
            'latestEarlyTerminationFlatSpreadTopUpTransaction',
            'latestEarlyTerminationContractTopUpTransaction',
        ]);
    }

    private static function contractSpread(InsuranceReceivable $record): ?string
    {
        if ($record->loan_outstanding === null || $record->contract_outstanding_amount === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $record->loan_outstanding)
            ->minus((string) $record->contract_outstanding_amount)
            ->toScale(2, RoundingMode::HalfUp);
    }
}
