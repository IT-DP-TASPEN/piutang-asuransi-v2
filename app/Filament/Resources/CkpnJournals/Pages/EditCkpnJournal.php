<?php

namespace App\Filament\Resources\CkpnJournals\Pages;

use App\Actions\CkpnJournal\ApproveCkpnJournalAction;
use App\Actions\CkpnJournal\RejectCkpnJournalAction;
use App\Actions\CkpnJournal\ReturnCkpnJournalAction;
use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use App\Jobs\ExecuteGlToGlJob;
use App\Models\CkpnJournal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCkpnJournal extends EditRecord
{
    protected static string $resource = CkpnJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('submit', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [CkpnJournal::STATUS_DRAFT, CkpnJournal::STATUS_RETURNED], true))
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(SubmitCkpnJournalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshJournalData();

                    Notification::make()->success()->title('CKPN journal submitted')->send();
                }),
            Action::make('approve')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('approve', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnJournal::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ApproveCkpnJournalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshJournalData();

                    Notification::make()->success()->title('CKPN journal approved')->send();
                }),
            Action::make('reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('reject', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnJournal::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(RejectCkpnJournalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshJournalData();

                    Notification::make()->success()->title('CKPN journal rejected')->send();
                }),
            Action::make('returnRequest')
                ->label('Return')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('returnRequest', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnJournal::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ReturnCkpnJournalAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshJournalData();

                    Notification::make()->success()->title('CKPN journal returned')->send();
                }),
            Action::make('executeGlToGl')
                ->label('Retry GL-to-GL')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('executeGlToGl', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnJournal::STATUS_GL_TO_GL_FAILED)
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    $this->getRecord()->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_QUEUED])->save();
                    ExecuteGlToGlJob::dispatch($this->getRecord()->id, $user->id)->afterCommit();

                    Notification::make()->success()->title('GL-to-GL retry queued')->send();
                }),
        ];
    }

    private function refreshJournalData(): void
    {
        $this->refreshFormData([
            'status',
            'approved_by',
            'approved_at',
        ]);
    }
}
