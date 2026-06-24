<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitManualEarlyTerminationConfirmationAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $activeRequest = $this->activeManualVerificationRequest($locked);

            if (! $user->can('submitManualEarlyTerminationConfirmation', $locked)) {
                throw ValidationException::withMessages([
                    'permission' => 'Only accounting maker can submit manual Early Termination confirmation.',
                ]);
            }

            if ($activeRequest instanceof ApprovalRequest && $locked->manualEarlyTerminationSubmitted()) {
                return $locked->refresh();
            }

            if (! $locked->canSubmitManualEarlyTerminationConfirmation()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Manual Early Termination confirmation can only be submitted when manual execution is pending.',
                ]);
            }

            $fromWorkflowStatus = $locked->workflow_status;
            $fromSystemStatus = $locked->system_status;

            if (! $activeRequest instanceof ApprovalRequest) {
                $activeRequest = $this->approvalService->submit(
                    approvable: $locked,
                    workflowCode: ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION,
                    actor: $user,
                    notes: $notes,
                    metadata: [
                        'from_workflow_status' => $fromWorkflowStatus,
                        'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
                        'from_system_status' => $fromSystemStatus,
                        'to_system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
                    ],
                );
            }

            $locked->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            ])->save();

            $this->stageLogger->log(
                receivable: $locked,
                event: 'manual_early_termination_confirmation_submitted',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
                description: $notes ?: 'Manual Early Termination confirmation submitted.',
                metadata: [
                    'notes' => $notes,
                    'from_workflow_status' => $fromWorkflowStatus,
                    'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
                    'from_system_status' => $fromSystemStatus,
                    'to_system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
                    'approval_request_id' => $activeRequest->id,
                ],
                actor: $user,
                approvalRequest: $activeRequest,
            );

            return $locked->refresh();
        });
    }

    private function activeManualVerificationRequest(InsuranceReceivable $insuranceReceivable): ?ApprovalRequest
    {
        return $insuranceReceivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();
    }
}
