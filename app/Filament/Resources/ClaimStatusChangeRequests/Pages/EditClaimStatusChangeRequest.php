<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests\Pages;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\ReturnClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\SubmitClaimStatusChangeRequestAction;
use App\Filament\Resources\ClaimStatusChangeRequests\ClaimStatusChangeRequestResource;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClaimStatusChangeRequest extends EditRecord
{
    protected static string $resource = ClaimStatusChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('submit', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [
                        ClaimStatusChangeRequest::STATUS_DRAFT,
                        ClaimStatusChangeRequest::STATUS_RETURNED,
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

                    app(SubmitClaimStatusChangeRequestAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['from_claim_status_id', 'status']);

                    Notification::make()->success()->title('Claim status request submitted')->send();
                }),
            Action::make('approve')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('approve', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')
                        ->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ApproveClaimStatusChangeRequestAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('Claim status request approved')->send();
                }),
            Action::make('reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('reject', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
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

                    app(RejectClaimStatusChangeRequestAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('Claim status request rejected')->send();
                }),
            Action::make('returnRequest')
                ->label('Return')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('returnRequest', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
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

                    app(ReturnClaimStatusChangeRequestAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('Claim status request returned')->send();
                }),
            DeleteAction::make(),
        ];
    }
}
