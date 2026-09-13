<?php

namespace App\Services\Approval;

use App\Actions\CkpnAdjustment\ApplyApprovedCkpnAdjustmentAction;
use App\Actions\InsuranceReceivable\QueueEarlyTerminationAction;
use App\Jobs\ExecuteGlToGlJob;
use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalFinalizationService
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly ApplyApprovedCkpnAdjustmentAction $applyApprovedCkpnAdjustmentAction,
        private readonly QueueEarlyTerminationAction $queueEarlyTerminationAction,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function finalize(ApprovalRequest $approvalRequest, User $actor, ?string $notes = null): void
    {
        match ($approvalRequest->workflow_code) {
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => $this->finalizeBranchSubmission($approvalRequest, $actor, $notes),
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => $this->finalizeAccountingValidation($approvalRequest, $actor, $notes),
            ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE => $this->finalizeClaimStatusUpdate($approvalRequest, $actor, $notes),
            ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL => $this->finalizeCkpnJournal($approvalRequest, $actor),
            ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT => $this->finalizeCkpnAdjustment($approvalRequest, $actor),
            default => null,
        };
    }

    private function finalizeBranchSubmission(ApprovalRequest $approvalRequest, User $actor, ?string $notes): void
    {
        $receivable = $approvalRequest->approvable;

        if (! $receivable instanceof InsuranceReceivable) {
            return;
        }

        $fromStatus = $receivable->workflow_status;

        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            'approved_at' => now(),
        ])->save();

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'initial_approval_chain_completed',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            description: 'Initial formation approval chain completed. Awaiting collectability change confirmation by IT.',
            actor: $actor,
            approvalRequest: $approvalRequest,
        );
    }

    private function finalizeAccountingValidation(ApprovalRequest $approvalRequest, User $actor, ?string $notes): void
    {
        $receivable = $approvalRequest->approvable;

        if (! $receivable instanceof InsuranceReceivable) {
            return;
        }

        $fromStatus = $receivable->workflow_status;

        DB::transaction(function () use ($receivable, $actor, $approvalRequest, $fromStatus, $notes): void {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->operAccount->assertMatches($locked);

            if (trim((string) $locked->collectability) !== '5') {
                throw ValidationException::withMessages([
                    'collectability' => 'Fresh collectability must remain 5 for receivable formation; actual: '.($locked->collectability ?: '(empty)').'.',
                ]);
            }

            $amount = $this->finalReceivableAmount($locked);

            $locked->forceFill([
                'saving_account_for_loan_repayment' => $this->operAccount->expected($locked),
                'receivable_formation_date' => now()->toDateString(),
                'receivable_amount' => $amount,
                'remaining_receivable_amount' => $amount,
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING,
                'last_error_message' => null,
                'approved_at' => now(),
            ])->save();

            $this->stageLogger->log(
                receivable: $locked,
                event: 'accounting_validation_approved',
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
                description: $notes ?: 'Accounting validation approved.',
                metadata: ['receivable_amount' => $amount],
                actor: $actor,
                approvalRequest: $approvalRequest,
            );

        });

        $this->queueEarlyTerminationAction->handle($receivable->refresh(), $actor);
    }

    private function finalReceivableAmount(InsuranceReceivable $receivable): string
    {
        $contract = $this->normalizedMoney($receivable->contract_outstanding_amount, 'Contract outstanding is required to form receivable.');
        $fincloud = $this->normalizedMoney($receivable->loan_outstanding, 'Fresh Fincloud outstanding is required to form receivable.');

        if (BigDecimal::of($contract)->isLessThanOrEqualTo('0')) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding must be greater than zero.',
            ]);
        }

        if (BigDecimal::of($contract)->isGreaterThan(BigDecimal::of($fincloud))) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding cannot exceed fresh Fincloud outstanding.',
            ]);
        }

        return $contract;
    }

    private function normalizedMoney(mixed $amount, string $message): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'loan_outstanding' => $message,
            ]);
        }

        return (string) BigDecimal::of(str_replace(',', '', (string) $amount))->toScale(2, RoundingMode::HalfUp);
    }

    private function finalizeClaimStatusUpdate(ApprovalRequest $approvalRequest, User $actor, ?string $notes): void
    {
        $request = $approvalRequest->approvable;

        if (! $request instanceof ClaimStatusChangeRequest) {
            return;
        }

        $receivable = $request->insuranceReceivable;
        $fromStatus = $receivable->claimStatus?->code;

        $receivable->forceFill([
            'claim_status_id' => $request->to_claim_status_id,
        ])->save();

        $request->forceFill([
            'status' => ClaimStatusChangeRequest::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'claim_status_update_approved',
            fromStatus: $fromStatus,
            toStatus: $request->toClaimStatus?->code,
            description: $notes ?: 'Claim status update approved.',
            actor: $actor,
            approvalRequest: $approvalRequest,
        );
    }

    private function finalizeCkpnJournal(ApprovalRequest $approvalRequest, User $actor): void
    {
        $journal = $approvalRequest->approvable;

        if (! $journal instanceof CkpnJournal) {
            return;
        }

        $journal->forceFill([
            'status' => CkpnJournal::STATUS_GL_TO_GL_QUEUED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        ExecuteGlToGlJob::dispatch($journal->id, $actor->id)->afterCommit();
    }

    private function finalizeCkpnAdjustment(ApprovalRequest $approvalRequest, User $actor): void
    {
        $adjustment = $approvalRequest->approvable;

        if (! $adjustment instanceof CkpnAdjustment) {
            return;
        }

        $this->applyApprovedCkpnAdjustmentAction->handle($adjustment, $actor);
    }
}
