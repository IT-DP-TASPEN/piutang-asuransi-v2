<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveEarlyTerminationManuallyAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly CoreBankingClient $coreBankingClient,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter Early Termination.',
            ]);
        }

        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:resolve-early-termination", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'system_status' => 'Early termination resolution verification is already in progress.',
            ]);
        }

        try {
            $eligibility = DB::transaction(function () use ($insuranceReceivable, $user): array {
                $locked = InsuranceReceivable::query()
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $context = $this->assertEligible($locked, $user);

                return [
                    'receivable' => $locked,
                    'manual_approval_request_id' => $context['manual_approval_request_id'],
                ];
            });
            /** @var InsuranceReceivable $eligible */
            $eligible = $eligibility['receivable'];
            $manualApprovalRequestId = $eligibility['manual_approval_request_id'];
            $loanAccountNumber = trim((string) $eligible->loan_account_number);
            $result = $this->coreBankingClient->inquireLoan(
                accountNumber: $loanAccountNumber,
                related: $eligible,
                requestedBy: $user,
            );
            $apiLog = $result['log_id'] === null
                ? null
                : ApiIntegrationLog::query()->find($result['log_id']);

            if ($result['response_code'] !== '77') {
                throw ValidationException::withMessages([
                    'loan_account_number' => $this->verificationFailureMessage($result),
                ]);
            }

            return DB::transaction(function () use (
                $insuranceReceivable,
                $user,
                $notes,
                $loanAccountNumber,
                $result,
                $apiLog,
                $manualApprovalRequestId,
            ): InsuranceReceivable {
                $locked = InsuranceReceivable::query()
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $context = $this->assertEligible($locked, $user);

                if ($context['manual_approval_request_id'] !== $manualApprovalRequestId) {
                    throw ValidationException::withMessages([
                        'approval' => 'Manual Early Termination verification approval changed during verification.',
                    ]);
                }

                if (trim((string) $locked->loan_account_number) !== $loanAccountNumber) {
                    throw ValidationException::withMessages([
                        'loan_account_number' => 'Loan account number changed during manual Early Termination verification.',
                    ]);
                }

                $fromWorkflowStatus = $locked->workflow_status;
                $fromSystemStatus = $locked->system_status;
                $makerSubmittedNotes = $manualApprovalRequestId === null
                    ? null
                    : $this->makerSubmittedNotes($manualApprovalRequestId);

                if ($manualApprovalRequestId !== null) {
                    $this->completeManualVerificationApproval(
                        approvalRequestId: $manualApprovalRequestId,
                        actor: $user,
                        notes: $notes,
                        metadata: [
                            'api_integration_log_id' => $apiLog?->id,
                            'verification_response_code' => $result['response_code'],
                            'verification_response_description' => $result['description'],
                            'loan_account_number' => $loanAccountNumber,
                        ],
                    );
                }

                $locked->forceFill([
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                    'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                    'last_error_message' => null,
                    'early_termination_resolved_at' => now(),
                ])->save();

                $this->stageLogger->log(
                    receivable: $locked,
                    event: 'early_termination_resolved_after_manual_core_execution',
                    fromStatus: $fromSystemStatus,
                    toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                    description: $notes ?: 'Early termination was executed manually in core and verified via loan inquiry response code 77.',
                    metadata: [
                        'from_workflow_status' => $fromWorkflowStatus,
                        'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                        'from_system_status' => $fromSystemStatus,
                        'to_system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                        'verification_response_code' => $result['response_code'],
                        'verification_response_description' => $result['description'],
                        'api_integration_log_id' => $apiLog?->id,
                        'loan_account_number' => $loanAccountNumber,
                        'manual_approval_request_id' => $manualApprovalRequestId,
                        'maker_submitted_notes' => $makerSubmittedNotes,
                        'approver_notes' => $notes,
                    ],
                    actor: $user,
                    apiLog: $apiLog,
                );

                return $locked->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{manual_approval_request_id: int|null}
     */
    private function assertEligible(InsuranceReceivable $insuranceReceivable, User $user): array
    {
        if (! $insuranceReceivable->canResolveEarlyTermination()) {
            throw ValidationException::withMessages([
                'system_status' => 'Only failed or manually executed Early Termination can be resolved.',
            ]);
        }

        if (! $user->can('resolveEarlyTermination', $insuranceReceivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can resolve Early Termination.',
            ]);
        }

        if (trim((string) $insuranceReceivable->loan_account_number) === '') {
            throw ValidationException::withMessages([
                'loan_account_number' => 'Loan account number is required to verify manual Early Termination.',
            ]);
        }

        $manualApprovalRequestId = null;

        if ($insuranceReceivable->manualEarlyTerminationSubmitted()) {
            $manualApprovalRequestId = $this->activeManualVerificationApprovalRequestId($insuranceReceivable);

            if ($manualApprovalRequestId === null) {
                throw ValidationException::withMessages([
                    'approval' => 'Active manual Early Termination verification approval request not found.',
                ]);
            }
        }

        return ['manual_approval_request_id' => $manualApprovalRequestId];
    }

    /**
     * @param  array{response_code: string|null, description: string|null, error_message: string|null}  $result
     */
    private function verificationFailureMessage(array $result): string
    {
        if ($result['response_code'] === '00') {
            return 'Loan is still found in core banking and has not been closed. Execute Early Termination manually in core before resolving.';
        }

        return $result['description']
            ?: $result['error_message']
            ?: 'Unable to verify that the loan was closed in core banking.';
    }

    private function activeManualVerificationApprovalRequestId(InsuranceReceivable $insuranceReceivable): ?int
    {
        $id = $insuranceReceivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function completeManualVerificationApproval(
        int $approvalRequestId,
        User $actor,
        ?string $notes,
        array $metadata,
    ): void {
        $request = ApprovalRequest::query()
            ->whereKey($approvalRequestId)
            ->where('workflow_code', ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->lockForUpdate()
            ->first();

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active manual Early Termination verification approval request not found.',
            ]);
        }

        $step = $request->steps()
            ->where('status', ApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();

        if (! $step instanceof ApprovalStep) {
            throw ValidationException::withMessages([
                'approval' => 'No pending manual Early Termination verification step found.',
            ]);
        }

        if (! $actor->hasRole('super_admin')) {
            if ($step->assigned_user_id !== null && $step->assigned_user_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'approval' => 'Approval step is assigned to another user.',
                ]);
            }

            if ($step->role_name !== null && ! $actor->hasRole($step->role_name)) {
                throw ValidationException::withMessages([
                    'approval' => "Approval step requires role {$step->role_name}.",
                ]);
            }
        }

        $step->forceFill([
            'status' => ApprovalStep::STATUS_APPROVED,
            'acted_by' => $actor->id,
            'acted_at' => now(),
            'notes' => $notes,
        ])->save();

        $request->logs()->create([
            'actor_id' => $actor->id,
            'action' => 'approved_step',
            'notes' => $notes,
            'metadata' => [
                'step_id' => $step->id,
                'step_order' => $step->step_order,
                ...$metadata,
            ],
        ]);

        $request->forceFill([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'final_approved_at' => now(),
        ])->save();

        $request->logs()->create([
            'actor_id' => $actor->id,
            'action' => 'approved',
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }

    private function makerSubmittedNotes(int $approvalRequestId): ?string
    {
        $request = ApprovalRequest::query()->find($approvalRequestId);

        if (! $request instanceof ApprovalRequest) {
            return null;
        }

        return $request->logs()
            ->where('action', 'submitted')
            ->latest('id')
            ->value('notes');
    }
}
