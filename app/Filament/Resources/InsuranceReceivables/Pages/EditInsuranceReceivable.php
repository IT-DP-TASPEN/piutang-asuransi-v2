<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditInsuranceReceivable extends EditRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runInquiry')
                ->label('Run Inquiry')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('runInquiry', $this->getRecord()) ?? false)
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(PerformLoanInquiryAction::class)->handle($this->getRecord(), $user);

                    $this->refreshFormData([
                        'branch_code',
                        'customer_name',
                        'alt_number',
                        'cif_no',
                        'cif_no_alt',
                        'loan_outstanding',
                        'receivable_amount',
                        'credit_limit',
                        'collectability',
                        'dpd',
                        'product_id',
                        'product_name',
                        'start_period',
                        'end_period',
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Loan inquiry completed')
                        ->send();
                }),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
