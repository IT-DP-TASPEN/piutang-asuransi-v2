<?php

namespace App\Filament\Resources\InsuranceReceivables\RelationManagers;

use App\Actions\ReceivablePayment\ExecuteReceivablePaymentRequestAction;
use App\Actions\ReceivablePayment\RejectReceivablePaymentRequestAction;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceivablePaymentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentRequests';

    protected static ?string $title = 'Payment requests';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('amount')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('payment_source')
                    ->formatStateUsing(fn (?string $state): string => ReceivablePaymentRequest::paymentSourceOptions()[$state] ?? (string) $state)
                    ->badge(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (?string $state): string => ReceivablePaymentRequest::statusOptions()[$state] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('requester.name')->label('Requested by')->sortable(),
                TextColumn::make('approver.name')->label('Approved by')->sortable(),
                TextColumn::make('last_error_message')->label('Last error')->wrap(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->visible(fn (ReceivablePaymentRequest $record): bool => (auth()->user()?->can('approve', $record) ?? false)
                        && $record->status === ReceivablePaymentRequest::STATUS_SUBMITTED)
                    ->requiresConfirmation()
                    ->form([Textarea::make('notes')->maxLength(65535)])
                    ->action(fn (ReceivablePaymentRequest $record, array $data): mixed => $this->execute($record, $data['notes'] ?? null)),
                Action::make('retry')
                    ->visible(fn (ReceivablePaymentRequest $record): bool => auth()->user()?->can('retry', $record) ?? false)
                    ->requiresConfirmation()
                    ->form([Textarea::make('notes')->maxLength(65535)])
                    ->action(fn (ReceivablePaymentRequest $record, array $data): mixed => $this->execute($record, $data['notes'] ?? null)),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (ReceivablePaymentRequest $record): bool => auth()->user()?->can('reject', $record) ?? false)
                    ->requiresConfirmation()
                    ->form([Textarea::make('notes')->required()->maxLength(65535)])
                    ->action(function (ReceivablePaymentRequest $record, array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        app(RejectReceivablePaymentRequestAction::class)->handle($record, $user, $data['notes'] ?? null);

                        Notification::make()->success()->title('Receivable payment request rejected')->send();
                    }),
            ]);
    }

    private function execute(ReceivablePaymentRequest $record, ?string $notes): ReceivablePaymentRequest
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $request = app(ExecuteReceivablePaymentRequestAction::class)->handle($record, $user, $notes);

        $request->status === ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED
            ? Notification::make()->success()->title('Receivable payment recorded')->send()
            : Notification::make()->warning()->title('Receivable payment not recorded')->body($request->last_error_message)->send();

        return $request;
    }
}
