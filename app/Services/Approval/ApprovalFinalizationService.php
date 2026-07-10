<?php

namespace App\Services\Approval;

use App\Actions\CkpnAdjustment\ApplyApprovedCkpnAdjustmentAction;
use App\Jobs\ExecuteGlToGlJob;
use App\Models\ApprovalRequest;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalFinalizationService
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly ApplyApprovedCkpnAdjustmentAction $applyApprovedCkpnAdjustmentAction,
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
            event: 'branch_approval_approved',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            description: $notes ?: 'BM approval completed. Awaiting collectability change confirmation by IT.',
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
            $amount = $this->finalReceivableAmount($locked);

            $locked->forceFill([
                'receivable_formation_date' => now()->toDateString(),
                'loan_outstanding' => $amount,
                'receivable_amount' => $amount,
                'remaining_receivable_amount' => $amount,
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING,
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

            $this->stageLogger->log(
                receivable: $locked,
                event: 'early_termination_confirmation_pending',
                fromStatus: null,
                toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING,
                description: 'Awaiting Accounting confirmation to execute early termination.',
                actor: $actor,
                approvalRequest: $approvalRequest,
            );
        });

    }

    private function finalReceivableAmount(InsuranceReceivable $receivable): string
    {
        $repayment = $receivable->installmentRepayment()->first();

        if ($repayment instanceof InsuranceReceivableInstallmentRepayment
            && in_array($repayment->status, [
                InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
            ], true)
            && $repayment->loan_outstanding_after !== null
        ) {
            return $this->normalizedMoney($repayment->loan_outstanding_after);
        }

        return $this->normalizedMoney($receivable->loan_outstanding);
    }

    private function normalizedMoney(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'loan_outstanding' => 'Loan outstanding is required to form receivable.',
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
