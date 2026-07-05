<?php

namespace App\Filament\Resources\RelationManagers;

use App\Actions\ReceivablePayment\RecordReceivablePaymentAction;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
                    ->visible(fn (): bool => $this->canCreatePayment())
                    ->using(function (array $data): ReceivablePayment {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        return app(RecordReceivablePaymentAction::class)->handle(
                            $this->getOwnerRecord(),
                            $data,
                            $user,
                        );
                    }),
            ])
            ->recordActions([]);
    }

    private function canCreatePayment(): bool
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof InsuranceReceivable || ! (auth()->user()?->can('Create:ReceivablePayment') ?? false)) {
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
}
