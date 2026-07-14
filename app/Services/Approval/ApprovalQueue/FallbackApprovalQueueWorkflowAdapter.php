<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Models\ApprovalRequest;

class FallbackApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return $request->workflow_code;
    }

    public function summary(ApprovalRequest $request): string
    {
        $approvable = $request->approvable;

        if (is_object($approvable)) {
            $summary = collect([
                $approvable->getAttribute('customer_name'),
                $approvable->getAttribute('loan_account_number'),
            ])->filter()->join(' - ');

            if ($summary !== '') {
                return $summary;
            }
        }

        return $request->approvable_type
            ? class_basename($request->approvable_type).' #'.$request->approvable_id
            : 'Unknown approvable';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return 'Approval #'.$request->id;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        return null;
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return null;
    }
}
