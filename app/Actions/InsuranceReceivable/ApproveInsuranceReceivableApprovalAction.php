<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Validation\ValidationException;

class ApproveInsuranceReceivableApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly ApproveAccountingValidationAction $approveAccountingValidationAction,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter formation workflow.',
            ]);
        }

        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be approved.',
            ]);
        }

        $request = $this->activeRequestFor($insuranceReceivable);

        if ($request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION) {
            return $this->approveAccountingValidationAction->handle($insuranceReceivable, $request, $user, $notes);
        }

        $this->approvalService->approveCurrentStep($request, $user, $notes);

        return $insuranceReceivable->refresh();
    }

    private function activeRequestFor(InsuranceReceivable $insuranceReceivable): ApprovalRequest
    {
        $request = $this->approvalService->latestActiveRequest($insuranceReceivable);

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active approval request not found.',
            ]);
        }

        return $request;
    }
}
