<?php

namespace App\Filament\Resources\LegacyReceivables\RelationManagers;

use App\Actions\LegacyReceivable\DeleteLegacyReceivablePaymentAction;
use App\Actions\LegacyReceivable\RecordLegacyReceivablePaymentAction;
use App\Actions\LegacyReceivable\UpdateLegacyReceivablePaymentAction;
use App\Models\LegacyReceivablePayment;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LegacyReceivablePaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->numeric()
                    ->step('0.01')
                    ->required(),
                DatePicker::make('paid_at')
                    ->default(now())
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                TextColumn::make('paid_at')->date()->sortable(),
                TextColumn::make('amount')->numeric(2)->sortable(),
                TextColumn::make('createdBy.name')->label('Created by')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('Create:LegacyReceivablePayment') ?? false)
                    ->using(function (array $data): LegacyReceivablePayment {
                        $user = auth()->user();

                        return app(RecordLegacyReceivablePaymentAction::class)->handle(
                            $this->getOwnerRecord(),
                            $data,
                            $user instanceof User ? $user : null,
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (LegacyReceivablePayment $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->using(fn (LegacyReceivablePayment $record, array $data): LegacyReceivablePayment => app(UpdateLegacyReceivablePaymentAction::class)->handle($record, $data)),
                DeleteAction::make()
                    ->visible(fn (LegacyReceivablePayment $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->action(function (LegacyReceivablePayment $record): void {
                        app(DeleteLegacyReceivablePaymentAction::class)->handle($record);
                    }),
            ]);
    }
}
