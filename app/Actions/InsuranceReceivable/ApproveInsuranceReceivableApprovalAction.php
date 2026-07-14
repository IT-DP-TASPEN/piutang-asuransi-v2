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
        $this->assertCanApprove($insuranceReceivable, $request, $user);

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

    private function assertCanApprove(InsuranceReceivable $insuranceReceivable, ApprovalRequest $request, User $user): void
    {
        if (! $user->can('approveApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized approvers can approve this receivable.',
            ]);
        }

        $expectedStatus = match ($request->workflow_code) {
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            default => null,
        };

        if ($expectedStatus === null || $insuranceReceivable->workflow_status !== $expectedStatus) {
            throw ValidationException::withMessages([
                'approval' => 'This approval request is no longer pending.',
            ]);
        }
    }
}
