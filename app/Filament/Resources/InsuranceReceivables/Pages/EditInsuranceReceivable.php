<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationAction;
use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitInsuranceReceivableForApprovalAction;
use App\Actions\InsuranceReceivable\SubmitReceivableFormationValidationAction;
use App\Actions\InsuranceReceivable\UpdateCollectabilityAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditInsuranceReceivable extends EditRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runInquiry')
                ->label('Run Inquiry')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('runInquiry', $this->getRecord()) ?? false)
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(PerformLoanInquiryAction::class)->handle($this->getRecord(), $user);

                    $this->refreshReceivableData();

                    Notification::make()
                        ->success()
                        ->title('Loan inquiry completed')
                        ->send();
                }),
            Action::make('submitForApproval')
                ->label('Submit')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('submitForApproval', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->workflow_status, [
                        InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                        InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
                    ], true))
                ->form([
                    Textarea::make('notes')
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(SubmitInsuranceReceivableForApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Submitted for approval')->send();
                }),
            Action::make('approveApproval')
                ->label('Approve')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('approveApproval', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->workflow_status, [
                        InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                        InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                    ], true))
                ->form([
                    Textarea::make('notes')
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ApproveInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Approval completed')->send();
                }),
            Action::make('rejectApproval')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('rejectApproval', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->workflow_status, [
                        InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                        InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                    ], true))
                ->form([
                    Textarea::make('notes')
                        ->required()
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(RejectInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Approval rejected')->send();
                }),
            Action::make('returnApproval')
                ->label('Return')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('returnApproval', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->workflow_status, [
                        InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                        InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                    ], true))
                ->form([
                    Textarea::make('notes')
                        ->required()
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ReturnInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Approval returned')->send();
                }),
            Action::make('updateCollectability')
                ->label('Update collectability')
                ->visible(fn (): bool => auth()->user()?->can('updateCollectability', $this->getRecord()) ?? false)
                ->form([
                    TextInput::make('collectability')
                        ->default(fn () => $this->getRecord()->collectability)
                        ->maxLength(255),
                    Textarea::make('reason')
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(UpdateCollectabilityAction::class)->handle(
                        $this->getRecord(),
                        $user,
                        $data['collectability'] ?? null,
                        $data['reason'] ?? null,
                    );
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Collectability updated')->send();
                }),
            Action::make('submitAccountingValidation')
                ->label('Accounting validation')
                ->visible(fn (): bool => (auth()->user()?->can('submitAccountingValidation', $this->getRecord()) ?? false)
                    && $this->getRecord()->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_BRANCH_APPROVED)
                ->form([
                    DatePicker::make('journal_date')
                        ->default(now())
                        ->required(),
                    TextInput::make('amount')
                        ->default(fn () => $this->getRecord()->loan_outstanding)
                        ->required()
                        ->numeric(),
                    TextInput::make('debit_account')
                        ->maxLength(255),
                    TextInput::make('credit_account')
                        ->maxLength(255),
                    Textarea::make('description')
                        ->maxLength(65535),
                    Textarea::make('notes')
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(SubmitReceivableFormationValidationAction::class)->handle($this->getRecord(), $user, $data, $data['notes'] ?? null);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Submitted for accounting validation')->send();
                }),
            Action::make('executeEarlyTermination')
                ->label('Execute early termination')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('executeEarlyTermination', $this->getRecord()) ?? false)
                    && $this->getRecord()->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED)
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ExecuteEarlyTerminationAction::class)->handle($this->getRecord(), $user);
                    $this->refreshReceivableData();

                    Notification::make()->success()->title('Early termination executed')->send();
                }),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    private function refreshReceivableData(): void
    {
        $this->refreshFormData([
            'branch_code',
            'customer_name',
            'alt_number',
            'cif_no',
            'cif_no_alt',
            'loan_outstanding',
            'receivable_amount',
            'receivable_formation_date',
            'credit_limit',
            'collectability',
            'dpd',
            'product_id',
            'product_name',
            'start_period',
            'end_period',
            'workflow_status',
        ]);
    }
}
