<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\InsuranceReceivable;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInsuranceReceivable extends EditRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        abort_unless(auth()->user()?->can('update', $this->getRecord()) ?? false, 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord()->refresh();

        if ($record->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER) {
            return;
        }

        if (in_array($record->system_status, [
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
        ], true)) {
            return;
        }

        app(InsuranceReceivableInquiryDispatcher::class)->dispatch($record, 'branch_return_reinquiry_queued');
    }
}
