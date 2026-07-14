<?php

namespace App\Services\Approval;

use App\Models\ApprovalRequest;
use App\Services\Approval\ApprovalQueue\CkpnAdjustmentApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\CkpnJournalApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\CkpnWorkpaperApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\ClaimStatusChangeApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\FallbackApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\InsuranceReceivableApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\ManualEarlyTerminationApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalQueue\ReceivablePaymentApprovalQueueWorkflowAdapter;

class ApprovalQueueWorkflowRegistry
{
    /**
     * @return array<string, class-string<ApprovalQueueWorkflowAdapter>>
     */
    public function adapterClasses(): array
    {
        return [
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => InsuranceReceivableApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => InsuranceReceivableApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION => ManualEarlyTerminationApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE => ClaimStatusChangeApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER => CkpnWorkpaperApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL => CkpnJournalApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT => CkpnAdjustmentApprovalQueueWorkflowAdapter::class,
            ApprovalRequest::WORKFLOW_RECEIVABLE_PAYMENT => ReceivablePaymentApprovalQueueWorkflowAdapter::class,
        ];
    }

    public function adapterFor(ApprovalRequest $request): ApprovalQueueWorkflowAdapter
    {
        return app($this->adapterClasses()[$request->workflow_code] ?? FallbackApprovalQueueWorkflowAdapter::class);
    }

    /**
     * @return list<string>
     */
    public function supportedWorkflowCodes(): array
    {
        return array_keys($this->adapterClasses());
    }
}
