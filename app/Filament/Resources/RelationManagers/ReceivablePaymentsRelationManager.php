<?php

namespace App\Filament\Resources\RelationManagers;

use App\Actions\ReceivablePayment\SubmitReceivablePaymentRequestAction;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceivablePaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->numeric()
                    ->step('0.01')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                TextColumn::make('paid_at')->date()->sortable(),
                TextColumn::make('amount')->money('IDR', 0, 'id_ID')->sortable(),
                TextColumn::make('createdBy.name')->label('Created by')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                Action::make('createPaymentRequest')
                    ->label('Create Receivable Payment')
                    ->visible(fn (): bool => $this->canCreatePaymentRequest())
                    ->schema([
                        Select::make('payment_source')
                            ->label('Payment Source')
                            ->options(ReceivablePaymentRequest::paymentSourceOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $this->clearSavingPreview($set);

                                if ($state === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING) {
                                    $this->fillSavingPreview($set);
                                }
                            }),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('preview_account_number')
                                    ->label('Account Number')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_customer_name')
                                    ->label('Customer Name')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_product_name')
                                    ->label('Product Name')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_document_status')
                                    ->label('Document Status')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_available_balance')
                                    ->label('Available Balance')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_ledger_balance')
                                    ->label('Ledger Balance')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('preview_error')
                                    ->label('Error')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull()
                                    ->visible(fn ($get): bool => $get('preview_error') !== null),
                            ])
                            ->visible(fn ($get): bool => $get('payment_source') === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING),
                        TextInput::make('amount')
                            ->numeric()
                            ->step('0.01')
                            ->required(),
                        Textarea::make('maker_notes')
                            ->maxLength(65535),
                    ])
                    ->action(function (array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        try {
                            app(SubmitReceivablePaymentRequestAction::class)->handle(
                                $this->getOwnerRecord(),
                                $data,
                                $user,
                            );
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Failed to submit receivable payment request')
                                ->body($exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Receivable payment request submitted')->send();
                    }),
            ])
            ->recordActions([]);
    }

    private function canCreatePaymentRequest(): bool
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof InsuranceReceivable || ! (auth()->user()?->can('Submit:ReceivablePaymentRequest') ?? false)) {
            return false;
        }

        if ($owner->trashed()) {
            return false;
        }

        if ($owner->isLegacyOrigin()) {
            return $owner->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED
                && $owner->system_status === InsuranceReceivable::SYSTEM_STATUS_LEGACY_IMPORTED;
        }

        return in_array($owner->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
        ], true);
    }

    private function fillSavingPreview(Set $set): void
    {
        $owner = $this->getOwnerRecord();
        $user = auth()->user();

        if (! $owner instanceof InsuranceReceivable) {
            return;
        }

        $account = trim((string) $owner->saving_account_for_loan_repayment);

        if ($account === '') {
            $set('preview_error', 'Saving account is empty.');

            return;
        }

        $result = app(CoreBankingClient::class)->inquireBalance($account, $owner, $user instanceof User ? $user : null);

        if (! $result['ok']) {
            $set('preview_error', $result['description'] ?: $result['error_message'] ?: 'Saving account inquiry failed.');

            return;
        }

        $data = $result['data'];
        $set('preview_account_number', $data['accountNumber'] ?? $data['account'] ?? $account);
        $set('preview_customer_name', $data['customerName'] ?? null);
        $set('preview_product_name', $data['productName'] ?? null);
        $set('preview_document_status', $data['documentStatus'] ?? null);
        $set('preview_available_balance', $data['availableBalance'] ?? null);
        $set('preview_ledger_balance', $data['ledgerBalance'] ?? null);
        $set('preview_error', null);
    }

    private function clearSavingPreview(Set $set): void
    {
        foreach (
            [
                'preview_account_number',
                'preview_customer_name',
                'preview_product_name',
                'preview_document_status',
                'preview_available_balance',
                'preview_ledger_balance',
                'preview_error',
            ] as $field
        ) {
            $set($field, null);
        }
    }
}
