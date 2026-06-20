<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Actions\Ckpn\RecalculateCkpnWorkpaperAction;
use App\Actions\Ckpn\ValidateCkpnJournalCreationAction;
use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\RejectCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\ReturnCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\SubmitCkpnWorkpaperAction;
use App\Actions\GeneratedExport\GenerateCkpnWorkpaperSakepExportAction;
use App\Filament\Resources\ApiIntegrationLogs\ApiIntegrationLogResource;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Filament\Resources\GeneratedExports\GeneratedExportResource;
use App\Filament\Resources\GlToGlTransactions\GlToGlTransactionResource;
use App\Jobs\ExecuteGlToGlJob;
use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\GeneratedExport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewCkpnWorkpaper extends ViewRecord
{
    protected static string $resource = CkpnWorkpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn(): bool => $this->isEditable()
                    && (auth()->user()?->can('update', $this->workpaper()) ?? false)),
            ActionGroup::make([
                $this->retryGenerationAction(),
                $this->recalculateAction(),
                $this->submitAction(),
            ])
                ->label('Workpaper')
                ->icon(Heroicon::OutlinedDocumentText)
                ->button()
                ->color('primary')
                ->visible(fn(): bool => $this->hasVisibleWorkpaperActions()),
            ActionGroup::make([
                $this->approveAction(),
                $this->rejectAction(),
                $this->returnAction(),
            ])
                ->label('Approval')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->button()
                ->color('success')
                ->visible(fn(): bool => $this->hasVisibleApprovalActions()),
            ActionGroup::make([
                $this->createJournalAction(),
                $this->generateSakepExportAction(),
                $this->downloadLatestSakepExportAction(),
            ])
                ->label('Output')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->button()
                ->color('warning')
                ->visible(fn(): bool => $this->hasVisibleOutputActions()),
            ActionGroup::make([
                $this->retryGlToGlAction(),
                $this->viewGeneratedExportsAction(),
                $this->viewGlToGlTransactionsAction(),
                $this->viewApiLogsAction(),
            ])
                ->label('System')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->button()
                ->color('gray')
                ->visible(fn(): bool => $this->hasVisibleSystemActions()),
        ];
    }

    private function retryGenerationAction(): Action
    {
        return Action::make('retryGeneration')
            ->label('Retry Generation')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canRetryGeneration())
            ->action(function (): void {
                $user = auth()->user();

                if (! ($user?->can('generate', $this->workpaper()) ?? false)) {
                    abort(403);
                }

                $workpaper = $this->workpaper();
                $workpaper->forceFill([
                    'status' => CkpnWorkpaper::STATUS_GENERATION_QUEUED,
                    'last_error_message' => null,
                ])->save();

                GenerateCkpnWorkpaperJob::dispatch($workpaper->id)->afterCommit();
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN generation retry queued')->send();
            });
    }

    private function recalculateAction(): Action
    {
        return Action::make('recalculate')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canRecalculate())
            ->action(function (): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                app(RecalculateCkpnWorkpaperAction::class)->handle($this->workpaper(), $user);
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN workpaper recalculated')->send();
            });
    }

    private function submitAction(): Action
    {
        return Action::make('submit')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canSubmit())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                app(SubmitCkpnWorkpaperAction::class)->handle($this->workpaper(), $user, $data['notes'] ?? null);
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN workpaper submitted')->send();
            });
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canApprove())
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                app(ApproveCkpnWorkpaperAction::class)->handle($this->workpaper(), $user, $data['notes'] ?? null);
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN workpaper approved')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canReject())
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                app(RejectCkpnWorkpaperAction::class)->handle($this->workpaper(), $user, $data['notes'] ?? null);
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN workpaper rejected')->send();
            });
    }

    private function returnAction(): Action
    {
        return Action::make('returnRequest')
            ->label('Return')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canReturn())
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                app(ReturnCkpnWorkpaperAction::class)->handle($this->workpaper(), $user, $data['notes'] ?? null);
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN workpaper returned')->send();
            });
    }

    private function createJournalAction(): Action
    {
        return Action::make('createJournal')
            ->label('Create CKPN journal')
            ->visible(fn(): bool => $this->canAttemptCreateJournal())
            ->modalDescription(fn(): ?string => $this->journalBlockingMessage())
            ->form([
                DatePicker::make('journal_date')
                    ->default(now())
                    ->required(),
                TextInput::make('debit_account')
                    ->maxLength(255),
                TextInput::make('credit_account')
                    ->maxLength(255),
                Textarea::make('debit_narrative')
                    ->maxLength(65535),
                Textarea::make('credit_narrative')
                    ->maxLength(65535),
                Textarea::make('description')
                    ->maxLength(65535),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(CreateCkpnJournalFromWorkpaperAction::class)->handle($this->workpaper(), $user, $data);
                } catch (ValidationException $exception) {
                    $this->notifyValidationFailure($exception);
                    $this->halt(true);
                }

                $this->refreshWorkpaperData();

                Notification::make()->success()->title('CKPN journal draft created')->send();
            });
    }

    private function generateSakepExportAction(): Action
    {
        return Action::make('generateSakepExport')
            ->label('Generate SAKEP XLSX')
            ->visible(fn(): bool => $this->canGenerateExport())
            ->action(function (): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $export = app(GenerateCkpnWorkpaperSakepExportAction::class)->handle($this->workpaper(), $user);
                $this->refreshWorkpaperData();

                if ($export->status === GeneratedExport::STATUS_FAILED) {
                    Notification::make()->danger()->title('SAKEP export failed')->send();

                    return;
                }

                Notification::make()->success()->title('SAKEP XLSX generated')->send();
            });
    }

    private function downloadLatestSakepExportAction(): Action
    {
        return Action::make('downloadLatestSakepExport')
            ->label('Download latest SAKEP')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn(): bool => $this->latestGeneratedExport() instanceof GeneratedExport)
            ->url(fn(): ?string => ($export = $this->latestGeneratedExport()) instanceof GeneratedExport
                ? route('generated-exports.download', $export)
                : null)
            ->openUrlInNewTab();
    }

    private function retryGlToGlAction(): Action
    {
        return Action::make('retryGlToGl')
            ->label('Retry GL-to-GL')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn(): bool => $this->canRetryGlToGl())
            ->action(function (): void {
                $user = auth()->user();
                $journal = $this->latestFailedGlJournal();

                if (! $user instanceof User || ! $journal instanceof CkpnJournal) {
                    return;
                }

                $journal->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_QUEUED])->save();
                ExecuteGlToGlJob::dispatch($journal->id, $user->id)->afterCommit();
                $this->refreshWorkpaperData();

                Notification::make()->success()->title('GL-to-GL retry queued')->send();
            });
    }

    private function viewGeneratedExportsAction(): Action
    {
        return Action::make('viewGeneratedExports')
            ->label('View Export Logs')
            ->visible(fn(): bool => auth()->user()?->can('ViewAny:GeneratedExport') ?? false)
            ->url(fn(): string => GeneratedExportResource::getUrl('index'));
    }

    private function viewGlToGlTransactionsAction(): Action
    {
        return Action::make('viewGlToGlTransactions')
            ->label('View GL-to-GL Logs')
            ->visible(fn(): bool => auth()->user()?->can('ViewAny:GlToGlTransaction') ?? false)
            ->url(fn(): string => GlToGlTransactionResource::getUrl('index'));
    }

    private function viewApiLogsAction(): Action
    {
        return Action::make('viewApiLogs')
            ->label('View API Logs')
            ->visible(fn(): bool => auth()->user()?->can('ViewAny:ApiIntegrationLog') ?? false)
            ->url(fn(): string => ApiIntegrationLogResource::getUrl('index'));
    }

    private function hasVisibleWorkpaperActions(): bool
    {
        return $this->canRetryGeneration() || $this->canRecalculate() || $this->canSubmit();
    }

    private function hasVisibleApprovalActions(): bool
    {
        return $this->canApprove() || $this->canReject() || $this->canReturn();
    }

    private function hasVisibleOutputActions(): bool
    {
        return $this->canAttemptCreateJournal()
            || $this->canGenerateExport()
            || $this->latestGeneratedExport() instanceof GeneratedExport;
    }

    private function hasVisibleSystemActions(): bool
    {
        return $this->canRetryGlToGl()
            || (auth()->user()?->can('ViewAny:GeneratedExport') ?? false)
            || (auth()->user()?->can('ViewAny:GlToGlTransaction') ?? false)
            || (auth()->user()?->can('ViewAny:ApiIntegrationLog') ?? false);
    }

    private function canRetryGeneration(): bool
    {
        return (auth()->user()?->can('generate', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_GENERATION_FAILED;
    }

    private function canRecalculate(): bool
    {
        return (auth()->user()?->can('recalculate', $this->workpaper()) ?? false)
            && in_array($this->workpaper()->status, [
                CkpnWorkpaper::STATUS_DRAFT,
                CkpnWorkpaper::STATUS_GENERATED,
                CkpnWorkpaper::STATUS_RETURNED,
            ], true);
    }

    private function canSubmit(): bool
    {
        return (auth()->user()?->can('submit', $this->workpaper()) ?? false)
            && in_array($this->workpaper()->status, [
                CkpnWorkpaper::STATUS_GENERATED,
            ], true)
            && $this->workpaper()->items()->exists();
    }

    private function canApprove(): bool
    {
        return (auth()->user()?->can('approve', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    private function canReject(): bool
    {
        return (auth()->user()?->can('reject', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    private function canReturn(): bool
    {
        return (auth()->user()?->can('returnRequest', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    private function canAttemptCreateJournal(): bool
    {
        return (auth()->user()?->can('createJournal', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_APPROVED
            && ! $this->workpaper()->journals()->exists();
    }

    private function canGenerateExport(): bool
    {
        return (auth()->user()?->can('generateExport', $this->workpaper()) ?? false)
            && $this->workpaper()->status === CkpnWorkpaper::STATUS_APPROVED;
    }

    private function latestGeneratedExport(): ?GeneratedExport
    {
        $export = $this->workpaper()
            ->generatedExports()
            ->where('status', GeneratedExport::STATUS_GENERATED)
            ->whereNotNull('file_path')
            ->latest('id')
            ->first();

        if (! $export instanceof GeneratedExport) {
            return null;
        }

        if (! (auth()->user()?->can('view', $export) ?? false)) {
            return null;
        }

        return $export;
    }

    private function canRetryGlToGl(): bool
    {
        $journal = $this->latestFailedGlJournal();

        return $journal instanceof CkpnJournal
            && (auth()->user()?->can('executeGlToGl', $journal) ?? false);
    }

    private function isEditable(): bool
    {
        return in_array($this->workpaper()->status, [
            CkpnWorkpaper::STATUS_DRAFT,
            CkpnWorkpaper::STATUS_RETURNED,
        ], true);
    }

    private function latestFailedGlJournal(): ?CkpnJournal
    {
        return $this->workpaper()
            ->journals()
            ->where('status', CkpnJournal::STATUS_GL_TO_GL_FAILED)
            ->latest('id')
            ->first();
    }

    private function workpaper(): CkpnWorkpaper
    {
        $record = $this->getRecord();

        if (! $record instanceof CkpnWorkpaper) {
            abort(404);
        }

        return $record;
    }

    private function refreshWorkpaperData(): void
    {
        $this->record = $this->workpaper()->refresh();
    }

    private function journalBlockingMessage(): ?string
    {
        $pending = $this->workpaper()
            ->adjustments()
            ->whereIn('status', ValidateCkpnJournalCreationAction::pendingAdjustmentStatuses())
            ->orderBy('id')
            ->get(['id', 'status']);

        if ($pending->isEmpty()) {
            return null;
        }

        $summary = $pending
            ->take(10)
            ->map(fn($adjustment): string => "#{$adjustment->id} ({$adjustment->status})")
            ->join(', ');

        $suffix = $pending->count() > 10 ? ', ...' : '';

        return "Cannot create CKPN Journal because {$pending->count()} CKPN Adjustments are still pending: {$summary}{$suffix}.";
    }

    private function notifyValidationFailure(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title($this->validationMessage($exception))
            ->send();
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->first() ?: $exception->getMessage();
    }
}
