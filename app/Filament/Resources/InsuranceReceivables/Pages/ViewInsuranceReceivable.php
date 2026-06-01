<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CreateAndSubmitClaimStatusChangeFromReceivableAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\ReturnClaimStatusChangeRequestAction;
use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitInsuranceReceivableForApprovalAction;
use App\Actions\InsuranceReceivable\SubmitReceivableFormationValidationAction;
use App\Filament\Resources\ApiIntegrationLogs\ApiIntegrationLogResource;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Jobs\ExecuteEarlyTerminationJob;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInsuranceReceivable extends ViewRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                $this->submitAction(),
                $this->submitAccountingValidationAction(),
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
                $this->retryEarlyTerminationAction(),
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

    private function submitAction(): Action
    {
        return Action::make('submitForApproval')
            ->label('Submit')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('submitForApproval', $this->getRecord()) ?? false)
                && in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                    InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
                ], true)
                && $this->getRecord()->system_status === InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(SubmitInsuranceReceivableForApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Submitted for approval')->send();
            });
    }

    private function submitAccountingValidationAction(): Action
    {
        return Action::make('submitAccountingValidation')
            ->label('Accounting validation')
            ->visible(fn (): bool => (auth()->user()?->can('submitAccountingValidation', $this->getRecord()) ?? false)
                && $this->getRecord()->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING)
            ->form([
                DatePicker::make('journal_date')->default(now())->required(),
                TextInput::make('amount')->default(fn () => $this->getRecord()->loan_outstanding)->required()->numeric(),
                TextInput::make('debit_account')->maxLength(255),
                TextInput::make('credit_account')->maxLength(255),
                Textarea::make('description')->maxLength(65535),
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(SubmitReceivableFormationValidationAction::class)->handle($this->getRecord(), $user, $data, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Submitted for accounting validation')->send();
            });
    }

    private function approveAction(): Action
    {
        return Action::make('approveApproval')
            ->label('Approve')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('approveApproval', $this->getRecord()) ?? false)
                && in_array($this->getRecord()->workflow_status, [
                    InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
                    InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                ], true))
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ApproveInsuranceReceivableApprovalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('Approval completed')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('rejectApproval')
            ->label('Reject')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('rejectApproval', $this->getRecord()) ?? false)
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
                && in_array($this->getRecord()->system_status, [
                    InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
                    InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED,
                ], true))
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
            ->visible(fn (): bool => (auth()->user()?->can('executeEarlyTermination', $this->getRecord()) ?? false)
                && $this->getRecord()->system_status === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED)
            ->action(function (): void {
                $user = auth()->user();
                $record = $this->getRecord();
                $fromStatus = $record->system_status;

                $record->forceFill([
                    'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
                    'last_error_message' => null,
                ])->save();

                app(InsuranceReceivableStageLogger::class)->log(
                    receivable: $record,
                    event: 'early_termination_retry_queued',
                    fromStatus: $fromStatus,
                    toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
                    description: 'Early termination retry queued.',
                    actor: $user instanceof User ? $user : null,
                );

                ExecuteEarlyTerminationJob::dispatch($record->id, $user instanceof User ? $user->id : null)->afterCommit();

                Notification::make()->success()->title('Early termination retry queued')->send();
            });
    }

    private function confirmCollectabilityChangeAction(): Action
    {
        return Action::make('confirmCollectabilityChange')
            ->label('Confirm Collectability Change Completed')
            ->requiresConfirmation()
            ->visible(fn (): bool => (auth()->user()?->can('confirmCollectabilityChange', $this->getRecord()) ?? false)
                && $this->getRecord()->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING)
            ->action(function (): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ConfirmCollectabilityChangeCompletedAction::class)->handle($this->getRecord(), $user);
                }

                Notification::make()->success()->title('Collectability change confirmed')->send();
            });
    }

    private function updateClaimStatusAction(): Action
    {
        return Action::make('updateClaimStatus')
            ->label('Update Claim Status')
            ->visible(fn (): bool => (auth()->user()?->can('Create:ClaimStatusChangeRequest') ?? false)
                && (auth()->user()?->can('Submit:ClaimStatusChangeRequest') ?? false)
                && ! $this->pendingClaimStatusRequest() instanceof ClaimStatusChangeRequest)
            ->form([
                TextInput::make('current_claim_status')
                    ->default(fn () => $this->getRecord()->claimStatus?->name)
                    ->disabled()
                    ->dehydrated(false),
                Select::make('to_claim_status_id')
                    ->label('Target claim status')
                    ->options(fn (): array => ClaimStatus::query()
                        ->where('is_active', true)
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

        $canSubmit = ($user?->can('submitForApproval', $record) ?? false)
            && in_array($record->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
            ], true)
            && $record->system_status === InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED;

        $canSubmitAccounting = ($user?->can('submitAccountingValidation', $record) ?? false)
            && $record->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING;

        $canApprove = ($user?->can('approveApproval', $record) ?? false) && $approvalPending;
        $canReject = ($user?->can('rejectApproval', $record) ?? false) && $approvalPending;
        $canReturn = ($user?->can('returnApproval', $record) ?? false) && $approvalPending;

        return $canSubmit || $canSubmitAccounting || $canApprove || $canReject || $canReturn;
    }

    private function hasVisibleSystemActions(): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();

        $canRetryInquiry = ($user?->can('runInquiry', $record) ?? false)
            && in_array($record->system_status, [
                InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
                InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED,
            ], true);

        $canRetryEarlyTermination = ($user?->can('executeEarlyTermination', $record) ?? false)
            && $record->system_status === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED;

        return $canRetryInquiry
            || $canRetryEarlyTermination
            || ($user?->can('confirmCollectabilityChange', $record) ?? false)
            && $record->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING
            || ($user?->can('ViewAny:ApiIntegrationLog') ?? false);
    }

    private function hasVisibleClaimActions(): bool
    {
        $user = auth()->user();

        $canCreateRequest = ($user?->can('Create:ClaimStatusChangeRequest') ?? false)
            && ($user?->can('Submit:ClaimStatusChangeRequest') ?? false)
            && ! $this->pendingClaimStatusRequest() instanceof ClaimStatusChangeRequest;

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

    private function canActOnPendingClaimStatus(string $ability): bool
    {
        $user = auth()->user();
        $request = $this->pendingClaimStatusRequest();

        return $user instanceof User
            && $request instanceof ClaimStatusChangeRequest
            && $user->can($ability, $request);
    }
}
