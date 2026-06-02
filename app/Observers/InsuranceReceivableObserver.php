<?php

namespace App\Observers;

use App\Models\InsuranceReceivable;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;

class InsuranceReceivableObserver
{
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
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ], true)) {
            return;
        }

        if (! in_array($insuranceReceivable->system_status, [
            null,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ], true)) {
            return;
        }

        if ($insuranceReceivable->isTerminal() || ! $insuranceReceivable->hasCompleteRequiredDocuments()) {
            return;
        }

        /** @var InsuranceReceivableInquiryDispatcher $dispatcher */
        $dispatcher = app(InsuranceReceivableInquiryDispatcher::class);
        $dispatcher->dispatch($insuranceReceivable, 'inquiry_requeued');
    }
}
