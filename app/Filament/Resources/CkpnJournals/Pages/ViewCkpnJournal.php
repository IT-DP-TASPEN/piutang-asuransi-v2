<?php

namespace App\Filament\Resources\CkpnJournals\Pages;

use App\Actions\CkpnJournal\ApproveCkpnJournalAction;
use App\Actions\CkpnJournal\RejectCkpnJournalAction;
use App\Actions\CkpnJournal\ReturnCkpnJournalAction;
use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use App\Jobs\ExecuteGlToGlJob;
use App\Models\CkpnJournal;
use App\Models\GlToGlTransaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCkpnJournal extends ViewRecord
{
    protected static string $resource = CkpnJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => auth()->user()?->can('update', $this->journal()) ?? false),
            ActionGroup::make([
                $this->submitAction(),
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
                $this->retryGlToGlAction(),
            ])
                ->label('System')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->canRetryGlToGl()),
        ];
    }

    private function submitAction(): Action
    {
        return Action::make('submit')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('submit', $this->journal()) ?? false)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                app(SubmitCkpnJournalAction::class)->handle($this->journal(), $user, $data['notes'] ?? null);
                $this->refreshJournalData();

                Notification::make()->success()->title('CKPN journal submitted')->send();
            });
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('approve', $this->journal()) ?? false)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                app(ApproveCkpnJournalAction::class)->handle($this->journal(), $user, $data['notes'] ?? null);
                $this->refreshJournalData();

                Notification::make()->success()->title('CKPN journal approved')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('reject', $this->journal()) ?? false)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                app(RejectCkpnJournalAction::class)->handle($this->journal(), $user, $data['notes'] ?? null);
                $this->refreshJournalData();

                Notification::make()->success()->title('CKPN journal rejected')->send();
            });
    }

    private function returnAction(): Action
    {
        return Action::make('returnRequest')
            ->label('Return')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('returnRequest', $this->journal()) ?? false)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                app(ReturnCkpnJournalAction::class)->handle($this->journal(), $user, $data['notes'] ?? null);
                $this->refreshJournalData();

                Notification::make()->success()->title('CKPN journal returned')->send();
            });
    }

    private function retryGlToGlAction(): Action
    {
        return Action::make('executeGlToGl')
            ->label('Retry GL-to-GL')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canRetryGlToGl())
            ->action(function (): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                $this->journal()->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_QUEUED])->save();
                ExecuteGlToGlJob::dispatch($this->journal()->id, $user->id)->afterCommit();
                $this->refreshJournalData();

                Notification::make()->success()->title('GL-to-GL retry queued')->send();
            });
    }

    private function hasVisibleApprovalActions(): bool
    {
        $user = auth()->user();

        return ($user?->can('submit', $this->journal()) ?? false)
            || ($user?->can('approve', $this->journal()) ?? false)
            || ($user?->can('reject', $this->journal()) ?? false)
            || ($user?->can('returnRequest', $this->journal()) ?? false);
    }

    private function canRetryGlToGl(): bool
    {
        $latest = $this->journal()
            ->glToGlTransactions()
            ->where('purpose', GlToGlTransaction::PURPOSE_CKPN_JOURNAL)
            ->latest('id')
            ->first();

        return $this->journal()->status === CkpnJournal::STATUS_GL_TO_GL_FAILED
            && (! $latest instanceof GlToGlTransaction || $latest->canRetry())
            && (auth()->user()?->can('executeGlToGl', $this->journal()) ?? false);
    }

    private function journal(): CkpnJournal
    {
        $record = $this->getRecord();

        if (! $record instanceof CkpnJournal) {
            abort(404);
        }

        return $record;
    }

    private function refreshJournalData(): void
    {
        $this->record = $this->journal()->refresh();
    }
}
