<?php

namespace App\Filament\Resources\InsuranceReceivables\Schemas;

use App\Models\ApprovalRequest;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
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
                    ->visible(fn(InsuranceReceivable $record): bool => $record->approvalRequests()
                        ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                        ->exists() && false)
                    ->schema([
                        TextEntry::make('pending_approval')
                            ->label('Request')
                            ->state(fn(InsuranceReceivable $record): ?string => $record->approvalRequests()
                                ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                                ->latest('id')
                                ->first()?->workflow_code),
                    ]),
                Section::make('Pending claim status update')
                    ->visible(fn(InsuranceReceivable $record): bool => $record->claimStatusChangeRequests()
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
}
