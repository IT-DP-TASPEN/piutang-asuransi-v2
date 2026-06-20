<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivableFormationJournal;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InsuranceReceivableInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->schema([
                        TextEntry::make('branch_code')->label('Branch'),
                        TextEntry::make('loan_account_number')->label('Loan account'),
                        TextEntry::make('customer_name')->label('Customer'),
                        TextEntry::make('insuranceCompany.name')->label('Insurance company'),
                        TextEntry::make('claimStatus.name')->label('Claim status')->badge(),
                        TextEntry::make('workflow_status')->label('Workflow status')->badge(),
                        TextEntry::make('system_status')->label('System status')->badge(),
                        TextEntry::make('last_error_message')->label('Last error')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Loan snapshot')
                    ->schema([
                        TextEntry::make('loan_outstanding')->label('Loan outstanding')->numeric(2),
                        TextEntry::make('receivable_amount')->label('Receivable amount')->numeric(2),
                        TextEntry::make('remaining_receivable_amount')->label('Remaining receivable')->numeric(2),
                        TextEntry::make('credit_limit')->label('Credit limit')->numeric(2),
                        TextEntry::make('collectability'),
                        TextEntry::make('dpd')->label('DPD'),
                        TextEntry::make('product_name')->label('Product'),
                        TextEntry::make('start_period')->date(),
                        TextEntry::make('end_period')->date(),
                        TextEntry::make('inquiry_completed_at')->dateTime(),
                        TextEntry::make('early_termination_executed_at')->dateTime(),
                    ])
                    ->columns(3),
                Section::make('Pending approval')
                    ->visible(fn (InsuranceReceivable $record): bool => $record->approvalRequests()
                        ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                        ->exists() && false)
                    ->schema([
                        TextEntry::make('pending_approval')
                            ->label('Request')
                            ->state(fn (InsuranceReceivable $record): ?string => $record->approvalRequests()
                                ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                ->latest('id')
                                ->first()?->workflow_code),
                    ]),
                Section::make('Accounting validation')
                    ->visible(fn (InsuranceReceivable $record): bool => $record->receivableFormationJournals()->exists()
                        && (auth()->user()?->can('view', $record) ?? false))
                    ->schema([
                        TextEntry::make('accounting_validation_journal_date')
                            ->label('Journal date')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->journal_date?->toDateString()),
                        TextEntry::make('accounting_validation_amount')
                            ->label('Amount')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->amount)
                            ->numeric(2),
                        TextEntry::make('accounting_validation_debit_account')
                            ->label('Debit account')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->debit_account),
                        TextEntry::make('accounting_validation_credit_account')
                            ->label('Credit account')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->credit_account),
                        TextEntry::make('accounting_validation_description')
                            ->label('Description')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->description)
                            ->columnSpanFull(),
                        TextEntry::make('accounting_validation_notes')
                            ->label('Notes')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->notes)
                            ->columnSpanFull(),
                        TextEntry::make('accounting_validation_submitter')
                            ->label('Submitted by')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->submitter?->name),
                        TextEntry::make('accounting_validation_submitted_at')
                            ->label('Submitted at')
                            ->state(fn (InsuranceReceivable $record): mixed => self::latestAccountingValidation($record)?->submitted_at)
                            ->dateTime(),
                        TextEntry::make('accounting_validation_status')
                            ->label('Status')
                            ->state(fn (InsuranceReceivable $record): ?string => self::latestAccountingValidation($record)?->status)
                            ->badge(),
                    ])
                    ->columns(3),
                Section::make('Pending claim status update')
                    ->visible(fn (InsuranceReceivable $record): bool => $record->claimStatusChangeRequests()
                        ->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)
                        ->exists() && false)
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
            ]);
    }

    private static function latestAccountingValidation(InsuranceReceivable $record): ?ReceivableFormationJournal
    {
        return $record->receivableFormationJournals()
            ->with('submitter')
            ->latest('id')
            ->first();
    }
}
