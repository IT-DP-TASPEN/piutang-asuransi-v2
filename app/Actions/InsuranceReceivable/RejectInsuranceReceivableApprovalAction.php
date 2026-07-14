<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectInsuranceReceivableApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
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
                'workflow_status' => 'Terminal receivables cannot be rejected.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $request = $this->activeRequestFor($insuranceReceivable);
            $this->assertCanReject($insuranceReceivable, $request, $user);

            $request = $this->approvalService->rejectCurrentStep($request, $user, $notes);
            $fromWorkflowStatus = $insuranceReceivable->workflow_status;

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: $request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION
                    ? 'accounting_validation_rejected'
                    : 'approval_rejected',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
                description: $notes ?: 'Approval rejected.',
                actor: $user,
                approvalRequest: $request,
            );

            return $insuranceReceivable->refresh();
        });
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

    private function assertCanReject(InsuranceReceivable $insuranceReceivable, ApprovalRequest $request, User $user): void
    {
        if (! $user->can('rejectApproval', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized approvers can reject this receivable.',
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
