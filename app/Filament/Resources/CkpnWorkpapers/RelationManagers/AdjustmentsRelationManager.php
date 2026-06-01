<?php

namespace App\Filament\Resources\CkpnWorkpapers\RelationManagers;

use App\Actions\CkpnAdjustment\ApproveCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\CancelCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\RejectCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\ReturnCkpnAdjustmentAction;
use App\Models\CkpnAdjustment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'adjustments';

    protected static ?string $title = 'CKPN adjustments';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('adjustment_type')
            ->columns([
                TextColumn::make('ckpnWorkpaperItem.loan_account_number')->label('Loan account')->searchable(),
                TextColumn::make('ckpnWorkpaperItem.customer_name')->label('Customer')->searchable(),
                TextColumn::make('adjustment_type')->searchable(),
                TextColumn::make('calculated_ckpn_amount')->label('Calculated')->numeric(2),
                TextColumn::make('requested_adjusted_ckpn_amount')->label('Requested')->numeric(2),
                TextColumn::make('approved_adjusted_ckpn_amount')->label('Approved')->numeric(2)->placeholder('-'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('requester.name')->label('Requested by')->sortable(),
                TextColumn::make('approver.name')->label('Approved by')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CkpnAdjustment::statusOptions()),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
                $this->returnAction(),
                $this->cancelAction(),
            ]);
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->requiresConfirmation()
            ->visible(fn (CkpnAdjustment $record): bool => (auth()->user()?->can('approve', $record) ?? false)
                && $record->status === CkpnAdjustment::STATUS_SUBMITTED)
            ->form([
                Textarea::make('notes')->maxLength(65535),
            ])
            ->action(function (CkpnAdjustment $record, array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ApproveCkpnAdjustmentAction::class)->handle($record, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('CKPN adjustment approved')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (CkpnAdjustment $record): bool => (auth()->user()?->can('reject', $record) ?? false)
                && $record->status === CkpnAdjustment::STATUS_SUBMITTED)
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (CkpnAdjustment $record, array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(RejectCkpnAdjustmentAction::class)->handle($record, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('CKPN adjustment rejected')->send();
            });
    }

    private function returnAction(): Action
    {
        return Action::make('returnRequest')
            ->label('Return')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (CkpnAdjustment $record): bool => (auth()->user()?->can('returnRequest', $record) ?? false)
                && $record->status === CkpnAdjustment::STATUS_SUBMITTED)
            ->form([
                Textarea::make('notes')->required()->maxLength(65535),
            ])
            ->action(function (CkpnAdjustment $record, array $data): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(ReturnCkpnAdjustmentAction::class)->handle($record, $user, $data['notes'] ?? null);
                }

                Notification::make()->success()->title('CKPN adjustment returned')->send();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (CkpnAdjustment $record): bool => (auth()->user()?->can('cancel', $record) ?? false)
                && in_array($record->status, [
                    CkpnAdjustment::STATUS_DRAFT,
                    CkpnAdjustment::STATUS_RETURNED,
                ], true))
            ->action(function (CkpnAdjustment $record): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    app(CancelCkpnAdjustmentAction::class)->handle($record, $user);
                }

                Notification::make()->success()->title('CKPN adjustment cancelled')->send();
            });
    }
}
