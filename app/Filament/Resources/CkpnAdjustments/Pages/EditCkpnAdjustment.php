<?php

namespace App\Filament\Resources\CkpnAdjustments\Pages;

use App\Actions\CkpnAdjustment\ApproveCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\RejectCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\ReturnCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\SubmitCkpnAdjustmentAction;
use App\Filament\Resources\CkpnAdjustments\CkpnAdjustmentResource;
use App\Models\CkpnAdjustment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCkpnAdjustment extends EditRecord
{
    protected static string $resource = CkpnAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('submit', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [
                        CkpnAdjustment::STATUS_DRAFT,
                        CkpnAdjustment::STATUS_RETURNED,
                    ], true))
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(SubmitCkpnAdjustmentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('CKPN adjustment submitted')->send();
                }),
            Action::make('approve')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('approve', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnAdjustment::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ApproveCkpnAdjustmentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('CKPN adjustment approved')->send();
                }),
            Action::make('reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('reject', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnAdjustment::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(RejectCkpnAdjustmentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('CKPN adjustment rejected')->send();
                }),
            Action::make('returnRequest')
                ->label('Return')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('returnRequest', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnAdjustment::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ReturnCkpnAdjustmentAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshFormData(['status']);

                    Notification::make()->success()->title('CKPN adjustment returned')->send();
                }),
        ];
    }
}
