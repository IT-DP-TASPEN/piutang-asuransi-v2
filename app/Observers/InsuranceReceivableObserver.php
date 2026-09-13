<?php

namespace App\Observers;

use App\Models\InsuranceReceivable;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;

class InsuranceReceivableObserver
{
    public function saving(InsuranceReceivable $insuranceReceivable): void
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            return;
        }

        if ($insuranceReceivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED) {
            return;
        }

        if (! $insuranceReceivable->isDirty('workflow_status') && ! $insuranceReceivable->isDirty('receivable_amount')) {
            return;
        }

        if ($insuranceReceivable->receivable_amount === null) {
            return;
        }

        if ($insuranceReceivable->exists && $insuranceReceivable->payments()->exists()) {
            return;
        }

        if (! in_array((string) $insuranceReceivable->remaining_receivable_amount, ['', '0', '0.00'], true)) {
            return;
        }

        $insuranceReceivable->remaining_receivable_amount = $insuranceReceivable->receivable_amount;
    }

    public function created(InsuranceReceivable $insuranceReceivable): void
    {
        app(InsuranceReceivableStageLogger::class)->log(
            receivable: $insuranceReceivable,
            event: 'record_created',
            fromStatus: null,
            toStatus: $insuranceReceivable->workflow_status,
            description: 'Insurance receivable draft created.',
            actor: $insuranceReceivable->creator,
        );
    }

    public function updated(InsuranceReceivable $insuranceReceivable): void
    {
        if (! $insuranceReceivable->wasChanged('loan_account_number')) {
            return;
        }

        if (! in_array($insuranceReceivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ], true)) {
            return;
        }

        if (! in_array($insuranceReceivable->system_status, [
            null,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
            InsuranceReceivable::SYSTEM_STATUS_REINQUIRY_REQUIRED,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ], true)) {
            return;
        }

        if ($insuranceReceivable->isTerminal()) {
            return;
        }

        /** @var InsuranceReceivableInquiryDispatcher $dispatcher */
        $dispatcher = app(InsuranceReceivableInquiryDispatcher::class);
        $dispatcher->dispatch($insuranceReceivable, 'inquiry_requeued');
    }
}
