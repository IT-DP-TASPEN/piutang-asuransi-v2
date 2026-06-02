<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests\Tables;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CancelClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\ReturnClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\SubmitClaimStatusChangeRequestAction;
use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClaimStatusChangeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Request #')
                    ->sortable(),
                TextColumn::make('insuranceReceivable.branch_code')
                    ->label('Branch')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('insuranceReceivable.loan_account_number')
                    ->label('Loan account')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('insuranceReceivable.customer_name')
                    ->label('Customer')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('fromClaimStatus.name')
                    ->label('From')
                    ->badge()
                    ->sortable(),
                TextColumn::make('toClaimStatus.name')
                    ->label('To')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ClaimStatusChangeRequest::statusOptions()),
            ])
            ->recordActions([
                Action::make('submit')
                    ->requiresConfirmation()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => (auth()->user()?->can('submit', $record) ?? false)
                        && in_array($record->status, [
                            ClaimStatusChangeRequest::STATUS_DRAFT,
                            ClaimStatusChangeRequest::STATUS_RETURNED,
                        ], true))
                    ->form([
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->action(function (ClaimStatusChangeRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(SubmitClaimStatusChangeRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Claim status request submitted')->send();
                    }),
                Action::make('approve')
                    ->requiresConfirmation()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => (auth()->user()?->can('approve', $record) ?? false)
                        && $record->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
                    ->form([
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->action(function (ClaimStatusChangeRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(ApproveClaimStatusChangeRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Claim status request approved')->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => (auth()->user()?->can('reject', $record) ?? false)
                        && $record->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
                    ->form([
                        Textarea::make('notes')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->action(function (ClaimStatusChangeRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(RejectClaimStatusChangeRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Claim status request rejected')->send();
                    }),
                Action::make('returnRequest')
                    ->label('Return')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => (auth()->user()?->can('returnRequest', $record) ?? false)
                        && $record->status === ClaimStatusChangeRequest::STATUS_SUBMITTED)
                    ->form([
                        Textarea::make('notes')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->action(function (ClaimStatusChangeRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(ReturnClaimStatusChangeRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Claim status request returned')->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->form([
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->action(function (ClaimStatusChangeRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(CancelClaimStatusChangeRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Claim status request cancelled')->send();
                    }),
                EditAction::make()
                    ->visible(fn (ClaimStatusChangeRequest $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }
}
