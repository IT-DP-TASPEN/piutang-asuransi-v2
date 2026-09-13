<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CreateAndSubmitClaimStatusChangeFromReceivableAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\ReturnClaimStatusChangeRequestAction;
use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\CancelInsuranceReceivableAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\QueueEarlyTerminationAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ResolveEarlyTerminationManuallyAction;
use App\Actions\InsuranceReceivable\ResolveEarlyTerminationTopUpReconciliationAction;
use App\Actions\InsuranceReceivable\ResolveInstallmentRepaymentAction;
use App\Actions\InsuranceReceivable\RetryInstallmentRepaymentAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitManualEarlyTerminationConfirmationAction;
use App\Filament\Resources\ApiIntegrationLogs\ApiIntegrationLogResource;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewInsuranceReceivable extends ViewRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false),
            ActionGroup::make([
                $this->approveAction(),
                $this->rejectAction(),
                $this->returnAction(),
            ])
                ->label('Approval')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->button()
                ->color('primary')
                ->visible(fn (): bool => $this->hasVisibleApprovalActions()),
            ActionGroup::make([
                $this->retryInquiryAction(),
                $this->retryInstallmentRepaymentAction(),
                $this->resolveInstallmentRepaymentAction(),
                $this->cancelReceivableAction(),
                $this->retryEarlyTerminationAction(),
                $this->resolveEarlyTerminationTopUpReconciliationAction(),
                $this->submitManualEarlyTerminationConfirmationAction(),
                $this->resolveEarlyTerminationAction(),
                $this->confirmCollectabilityChangeAction(),
                Action::make('viewApiLogs')
                    ->label('View API Logs')
                    ->visible(fn (): bool => auth()->user()?->can('ViewAny:ApiIntegrationLog') ?? false)
                    ->url(ApiIntegrationLogResource::getUrl('index')),
            ])
                ->label('System')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->hasVisibleSystemActions()),
            ActionGroup::make([
                $this->updateClaimStatusAction(),
                $this->approveClaimStatusAction(),
                $this->rejectClaimStatusAction(),
                $this->returnClaimStatusAction(),
            ])
                ->label('Claim Status')
                ->icon(Heroicon::OutlinedTag)
                ->button()
                ->color('warning')
                ->visible(fn (): bool => $this->hasVisibleClaimActions()),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approveApproval')
            ->label('Approve')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('approveApproval', $this->getRecord()) ?? false)
                && ! $this->getRecord()->isTerminal()
                && $this->canActOnCurrentApproval()
                && in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                    InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                ], true))
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                try {
                    app(ApproveInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    Notification::make()->success()->title('Approval completed')->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Error approving')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('rejectApproval')
            ->label('Reject')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('rejectApproval', $this->getRecord()) ?? false)
                && ! $this->getRecord()->isTerminal()
                && $this->canActOnCurrentApproval()
                && in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                    InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                ], true))
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(RejectInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Approval rejected')->send();
            });
    }

    private function returnAction(): Action
    {
        return Action::make('returnApproval')
            ->label('Return')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('returnApproval', $this->getRecord()) ?? false)
                && ! $this->getRecord()->isTerminal()
                && $this->canActOnCurrentApproval()
                && in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                    InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                ], true))
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ReturnInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Approval returned')->send();
            });
    }

    private function retryInquiryAction(): Action
    {
        return Action::make('retryInquiry')
            ->label('Retry Inquiry')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('runInquiry', $this->getRecord()) ?? false)
                && $this->getRecord()->canRetryInquiry())
            ->action(function (): void {
                app(InsuranceReceivableInquiryDispatcher::class)->dispatch($this->getRecord(), 'inquiry_retry_queued');

                Notification::make()->success()->title('Loan inquiry retry queued')->send();
            });
    }

    private function retryEarlyTerminationAction(): Action
    {
        return Action::make('retryEarlyTermination')
            ->label('Retry Early Termination')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Retry reuses proven funding components, then reruns balance, fresh-loan, and Early Termination checks. Use Resolve only when Early Termination has already been executed manually in core.')
            ->visible(fn (): bool => (auth()->user()?->can('executeEarlyTermination', $this->getRecord()) ?? false)
                && ! $this->getRecord()->isTerminal()
                && in_array($this->getRecord()->system_status, [
                    InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
                    InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
                ], true)
                && $this->canRetryEarlyTerminationCore()
                && ! in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
                    InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
                ], true))
            ->action(function (): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(QueueEarlyTerminationAction::class)->handle($this->getRecord(), $user);
                }

                Notification::make()->success()->title('Early termination retry queued')->send();
            });
    }

    private function retryInstallmentRepaymentAction(): Action
    {
        return Action::make('retryInstallmentRepayment')
            ->label('Retry Repayment')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Retry re-inquires loan and balance before posting repayment again. Unknown or already-posted states must use Resolve Repayment.')
            ->visible(fn (): bool => (auth()->user()?->can('retryInstallmentRepayment', $this->getRecord()) ?? false)
                && $this->getRecord()->canRetryInstallmentRepayment())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(RetryInstallmentRepaymentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->record = $this->getRecord()->refresh();
                }

                Notification::make()->success()->title('Installment repayment retried')->send();
            });
    }

    private function resolveInstallmentRepaymentAction(): Action
    {
        return Action::make('resolveInstallmentRepayment')
            ->label('Resolve Repayment')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Use after repayment was verified or performed manually in core. The app verifies that loan outstanding decreased.')
            ->visible(fn (): bool => (auth()->user()?->can('resolveInstallmentRepayment', $this->getRecord()) ?? false)
                && $this->getRecord()->canResolveInstallmentRepayment())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ResolveInstallmentRepaymentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->record = $this->getRecord()->refresh();
                }

                Notification::make()->success()->title('Installment repayment resolved')->send();
            });
    }

    private function resolveEarlyTerminationTopUpReconciliationAction(): Action
    {
        return Action::make('resolveEarlyTerminationTopUpReconciliation')
            ->label('Resolve Top Up Transaction')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Resolve only after verifying the exact GL reference in Core. OPER balance does not prove this transaction posted.')
            ->visible(fn (): bool => (auth()->user()?->can('reconcileEarlyTerminationTopUp', $this->getRecord()) ?? false))
            ->form([
                Select::make('purpose')
                    ->label('Component')
                    ->options(fn (): array => $this->getRecord()
                        ->glToGlTransactions()
                        ->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
                        ->whereIn('purpose', [
                            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
                        ])
                        ->get()
                        ->mapWithKeys(fn (GlToGlTransaction $transaction): array => [
                            $transaction->purpose => collect([
                                $transaction->purpose === GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP ? 'LSA' : 'PiutangAsuransi',
                                $transaction->reference_number,
                                'IDR '.($transaction->request_payload['amount'] ?? '-'),
                            ])->join(' | '),
                        ])
                        ->all())
                    ->required(),
                Select::make('outcome')
                    ->label('Manual decision')
                    ->options([
                        GlToGlTransaction::RESOLUTION_OUTCOME_POSTED => 'Mark as Posted',
                        GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED => 'Mark as Not Posted',
                    ])
                    ->required(),
                Textarea::make('notes')
                    ->label('Reconciliation reason')
                    ->required()
                    ->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                try {
                    app(ResolveEarlyTerminationTopUpReconciliationAction::class)
                        ->handle($this->getRecord(), $data['purpose'], $data['outcome'], $user, $data['notes'] ?? null);
                    $this->record = $this->getRecord()->refresh();

                    Notification::make()->success()->title('Top up reconciliation resolved')->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Error resolving top up reconciliation')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    private function submitManualEarlyTerminationConfirmationAction(): Action
    {
        return Action::make('submitManualEarlyTerminationConfirmation')
            ->label('Submit Manual Early Termination Confirmation')
            ->requiresConfirmation()
            ->modalDescription('Submit only after Accounting Maker has executed Early Termination manually in core. This only forwards the confirmation to Accounting Approver.')
            ->visible(fn (): bool => (auth()->user()?->can('submitManualEarlyTerminationConfirmation', $this->getRecord()) ?? false)
                && $this->getRecord()->canSubmitManualEarlyTerminationConfirmation())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(SubmitManualEarlyTerminationConfirmationAction::class)
                        ->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->record = $this->getRecord()->refresh();
                }

                Notification::make()->success()->title('Manual Early Termination confirmation submitted')->send();
            });
    }

    private function cancelReceivableAction(): Action
    {
        return Action::make('cancelReceivable')
            ->label('Cancel')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('cancel', $this->getRecord()) ?? false)
                && $this->getRecord()->canCancelFailedInquiry())
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(CancelInsuranceReceivableAction::class)->handle($this->getRecord(), $user, $data['notes']);
                    $this->record = $this->getRecord()->refresh();
                }

                Notification::make()->success()->title('Insurance receivable cancelled')->send();
            });
    }

    private function resolveEarlyTerminationAction(): Action
    {
        return Action::make('resolveEarlyTermination')
            ->label('Verify & Resolve Early Termination')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Use this only after Early Termination has been executed manually in core. The app only verifies the loan account through inquiry and resolves it when core returns response code 77 (Data Not Found).')
            ->visible(fn (): bool => (auth()->user()?->can('resolveEarlyTermination', $this->getRecord()) ?? false)
                && $this->getRecord()->canResolveEarlyTermination())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                try {
                    app(ResolveEarlyTerminationManuallyAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->record = $this->getRecord()->refresh();

                    Notification::make()->success()->title('Early termination resolved')->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Error resolving early termination')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    private function confirmCollectabilityChangeAction(): Action
    {
        return Action::make('confirmCollectabilityChange')
            ->label('Confirm Collectability Change Completed')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('confirmCollectabilityChange', $this->getRecord()) ?? false)
                && ! $this->getRecord()->isTerminal()
                && $this->getRecord()->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING)
            ->action(function (): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                try {
                    $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($this->getRecord(), $user);
                    $this->record = $receivable;

                    if ($receivable->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER) {
                        Notification::make()->warning()->title('Returned to Branch Maker')->body($receivable->last_error_message)->send();

                        return;
                    }

                    Notification::make()->success()->title('Collectability change confirmed')->send();
                } catch (\Throwable $e) {
                    $message = (string) ($e instanceof ValidationException
                        ? collect($e->errors())->flatten()->first()
                        : $e->getMessage());
                    Notification::make()->danger()->title('Collectability confirmation blocked')->body($message)->send();
                }
            });
    }

    private function updateClaimStatusAction(): Action
    {
        return Action::make('updateClaimStatus')
            ->label('Update Claim Status')
            ->visible(fn (): bool => (auth()->user()?->can('Create:ClaimStatusChangeRequest') ?? false)
                && (auth()->user()?->can('Submit:ClaimStatusChangeRequest') ?? false)
                && ! $this->getRecord()->isTerminal()
                && ! $this->hasOpenClaimStatusRequest())
            ->form([
                TextInput::make('current_claim_status')
                    ->default(fn () => $this->getRecord()->claimStatus?->name)
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
                Textarea::make('reason')->maxLength(65535),
                FileUpload::make('supporting_document_path')
                    ->label('Supporting document')
                    ->disk('public')
                    ->directory('claim-status-change-documents'),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(CreateAndSubmitClaimStatusChangeFromReceivableAction::class)->handle($this->getRecord(), $user, $data);
                }

                Notification::make()->success()->title('Claim status update submitted')->send();
            });
    }

    private function approveClaimStatusAction(): Action
    {
        return Action::make('approveClaimStatusUpdate')
            ->label('Approve Claim Status Update')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canActOnPendingClaimStatus('approve'))
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                $request = $this->pendingClaimStatusRequest();

                if ($user instanceof User && $request instanceof ClaimStatusChangeRequest) {
                    app(ApproveClaimStatusChangeRequestAction::class)->handle($request, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Claim status update approved')->send();
            });
    }

    private function rejectClaimStatusAction(): Action
    {
        return Action::make('rejectClaimStatusUpdate')
            ->label('Reject Claim Status Update')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canActOnPendingClaimStatus('reject'))
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                $request = $this->pendingClaimStatusRequest();

                if ($user instanceof User && $request instanceof ClaimStatusChangeRequest) {
                    app(RejectClaimStatusChangeRequestAction::class)->handle($request, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Claim status update rejected')->send();
            });
    }

    private function returnClaimStatusAction(): Action
    {
        return Action::make('returnClaimStatusUpdate')
            ->label('Return Claim Status Update')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canActOnPendingClaimStatus('returnRequest'))
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                $request = $this->pendingClaimStatusRequest();

                if ($user instanceof User && $request instanceof ClaimStatusChangeRequest) {
                    app(ReturnClaimStatusChangeRequestAction::class)->handle($request, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Claim status update returned')->send();
            });
    }

    private function hasVisibleApprovalActions(): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();
        $approvalPending = in_array($record->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
        ], true);

        $canAct = $this->canActOnCurrentApproval();
        $canApprove = ($user?->can('approveApproval', $record) ?? false) && ! $record->isTerminal() && $approvalPending && $canAct;
        $canReject = ($user?->can('rejectApproval', $record) ?? false) && ! $record->isTerminal() && $approvalPending && $canAct;
        $canReturn = ($user?->can('returnApproval', $record) ?? false) && ! $record->isTerminal() && $approvalPending && $canAct;

        return $canApprove || $canReject || $canReturn;
    }

    private function hasVisibleSystemActions(): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();

        $canRetryInquiry = ($user?->can('runInquiry', $record) ?? false)
            && $record->canRetryInquiry();

        $canRetryEarlyTermination = ($user?->can('executeEarlyTermination', $record) ?? false)
            && ! $record->isTerminal()
            && in_array($record->system_status, [
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            ], true)
            && $this->canRetryEarlyTerminationCore()
            && ! in_array($record->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
                InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
            ], true);

        $canCancel = ($user?->can('cancel', $record) ?? false)
            && $record->canCancelFailedInquiry();

        $canSubmitManualEarlyTerminationConfirmation = ($user?->can('submitManualEarlyTerminationConfirmation', $record) ?? false)
            && $record->canSubmitManualEarlyTerminationConfirmation();

        $canResolveEarlyTermination = ($user?->can('resolveEarlyTermination', $record) ?? false)
            && $record->canResolveEarlyTermination();

        $canResolveEarlyTerminationTopUpReconciliation = ($user?->can('reconcileEarlyTerminationTopUp', $record) ?? false)
            && $record->canReconcileEarlyTerminationTopUp();

        $canRetryInstallmentRepayment = ($user?->can('retryInstallmentRepayment', $record) ?? false)
            && $record->canRetryInstallmentRepayment();

        $canResolveInstallmentRepayment = ($user?->can('resolveInstallmentRepayment', $record) ?? false)
            && $record->canResolveInstallmentRepayment();

        return $canRetryInquiry
            || $canRetryInstallmentRepayment
            || $canResolveInstallmentRepayment
            || $canCancel
            || $canRetryEarlyTermination
            || $canResolveEarlyTerminationTopUpReconciliation
            || $canSubmitManualEarlyTerminationConfirmation
            || $canResolveEarlyTermination
            || (($user?->can('confirmCollectabilityChange', $record) ?? false)
                && ! $record->isTerminal()
                && $record->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING)
            || ($user?->can('ViewAny:ApiIntegrationLog') ?? false);
    }

    private function canRetryEarlyTerminationCore(): bool
    {
        $latest = $this->getRecord()
            ->earlyTerminationTransactions()
            ->latest('id')
            ->first();

        return ! $latest instanceof EarlyTerminationTransaction || $latest->canRetry();
    }

    private function canActOnCurrentApproval(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $request = app(ApprovalService::class)->latestActiveRequest($this->getRecord());

        return $request !== null && app(ApprovalService::class)->canActOnCurrentStep($request, $user);
    }

    private function hasVisibleClaimActions(): bool
    {
        $user = auth()->user();

        $canCreateRequest = ($user?->can('Create:ClaimStatusChangeRequest') ?? false)
            && ($user?->can('Submit:ClaimStatusChangeRequest') ?? false)
            && ! $this->getRecord()->isTerminal()
            && ! $this->hasOpenClaimStatusRequest();

        return $canCreateRequest
            || $this->canActOnPendingClaimStatus('approve')
            || $this->canActOnPendingClaimStatus('reject')
            || $this->canActOnPendingClaimStatus('returnRequest');
    }

    private function pendingClaimStatusRequest(): ?ClaimStatusChangeRequest
    {
        return $this->getRecord()
            ->claimStatusChangeRequests()
            ->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();
    }

    private function hasOpenClaimStatusRequest(): bool
    {
        return $this->getRecord()
            ->claimStatusChangeRequests()
            ->whereIn('status', [
                ClaimStatusChangeRequest::STATUS_DRAFT,
                ClaimStatusChangeRequest::STATUS_SUBMITTED,
                ClaimStatusChangeRequest::STATUS_RETURNED,
            ])
            ->exists();
    }

    private function canActOnPendingClaimStatus(string $ability): bool
    {
        $user = auth()->user();
        $request = $this->pendingClaimStatusRequest();

        return $user instanceof User
            && $request instanceof ClaimStatusChangeRequest
            && ! $this->getRecord()->isTerminal()
            && $user->can($ability, $request);
    }
}
