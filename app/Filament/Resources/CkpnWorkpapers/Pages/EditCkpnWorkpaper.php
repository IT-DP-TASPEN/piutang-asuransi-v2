<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Actions\Ckpn\GenerateMonthlyCkpnWorkpaperAction;
use App\Actions\Ckpn\RecalculateCkpnWorkpaperAction;
use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\RejectCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\ReturnCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\SubmitCkpnWorkpaperAction;
use App\Actions\GeneratedExport\GenerateCkpnWorkpaperSakepExportAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\CkpnWorkpaper;
use App\Models\GeneratedExport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCkpnWorkpaper extends EditRecord
{
    protected static string $resource = CkpnWorkpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('generate', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [
                        CkpnWorkpaper::STATUS_DRAFT,
                        CkpnWorkpaper::STATUS_GENERATED,
                    ], true))
                ->action(function (): void {
                    app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($this->getRecord());
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper generated')->send();
                }),
            Action::make('recalculate')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('recalculate', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [
                        CkpnWorkpaper::STATUS_DRAFT,
                        CkpnWorkpaper::STATUS_GENERATED,
                    ], true))
                ->action(function (): void {
                    app(RecalculateCkpnWorkpaperAction::class)->handle($this->getRecord());
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper recalculated')->send();
                }),
            Action::make('submit')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('submit', $this->getRecord()) ?? false)
                    && in_array($this->getRecord()->status, [
                        CkpnWorkpaper::STATUS_GENERATED,
                        CkpnWorkpaper::STATUS_RETURNED,
                    ], true))
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(SubmitCkpnWorkpaperAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper submitted')->send();
                }),
            Action::make('approve')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('approve', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnWorkpaper::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ApproveCkpnWorkpaperAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper approved')->send();
                }),
            Action::make('reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('reject', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnWorkpaper::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(RejectCkpnWorkpaperAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper rejected')->send();
                }),
            Action::make('returnRequest')
                ->label('Return')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => (auth()->user()?->can('returnRequest', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnWorkpaper::STATUS_SUBMITTED)
                ->form([
                    Textarea::make('notes')->required()->maxLength(65535),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ReturnCkpnWorkpaperAction::class)->handle($this->getRecord(), $user, $data['notes'] ?? null);
                    $this->refreshWorkpaperData();

                    Notification::make()->success()->title('CKPN workpaper returned')->send();
                }),
            Action::make('createJournal')
                ->label('Create CKPN journal')
                ->visible(fn (): bool => (auth()->user()?->can('createJournal', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnWorkpaper::STATUS_APPROVED)
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

                    app(CreateCkpnJournalFromWorkpaperAction::class)->handle($this->getRecord(), $user, $data);

                    Notification::make()->success()->title('CKPN journal draft created')->send();
                }),
            Action::make('generateSakepExport')
                ->label('Generate SAKEP XLSX')
                ->visible(fn (): bool => (auth()->user()?->can('generateExport', $this->getRecord()) ?? false)
                    && $this->getRecord()->status === CkpnWorkpaper::STATUS_APPROVED)
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    $export = app(GenerateCkpnWorkpaperSakepExportAction::class)->handle($this->getRecord(), $user);

                    if ($export->status === GeneratedExport::STATUS_FAILED) {
                        Notification::make()->danger()->title('SAKEP export failed')->send();

                        return;
                    }

                    Notification::make()->success()->title('SAKEP XLSX generated')->send();
                }),
        ];
    }

    private function refreshWorkpaperData(): void
    {
        $this->refreshFormData([
            'status',
            'total_receivable_amount',
            'total_ckpn_amount',
            'approved_by',
            'approved_at',
        ]);
    }
}
