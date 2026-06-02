<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivableFormationJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitReceivableFormationValidationAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    /**
     * @param  array{journal_date?: mixed, amount?: mixed, debit_account?: string|null, credit_account?: string|null, description?: string|null}  $data
     */
    public function handle(InsuranceReceivable $insuranceReceivable, User $user, array $data = [], ?string $notes = null): InsuranceReceivable
    {
        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot be submitted for accounting validation.',
            ]);
        }

        if (! in_array($insuranceReceivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_ACCOUNTING_MAKER,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Only receivables pending or returned to accounting maker can be submitted for accounting validation.',
            ]);
        }

        return DB::transaction(function () use ($insuranceReceivable, $user, $data, $notes): InsuranceReceivable {
            $fromWorkflowStatus = $insuranceReceivable->workflow_status;
            $journal = $insuranceReceivable->receivableFormationJournals()->create([
                'journal_date' => $data['journal_date'] ?? now()->toDateString(),
                'amount' => $this->amount($data['amount'] ?? $insuranceReceivable->loan_outstanding),
                'debit_account' => $data['debit_account'] ?? null,
                'credit_account' => $data['credit_account'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ReceivableFormationJournal::STATUS_SUBMITTED,
                'created_by' => $user->id,
            ]);

            $approvalRequest = $this->approvalService->submit(
                approvable: $insuranceReceivable,
                workflowCode: ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
                actor: $user,
                notes: $notes,
            );

            $insuranceReceivable->forceFill([
                'receivable_formation_date' => $journal->journal_date,
                'receivable_amount' => $journal->amount,
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: 'accounting_validation_submitted',
                fromStatus: $fromWorkflowStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                description: 'Accounting validation submitted.',
                actor: $user,
                approvalRequest: $approvalRequest,
            );

            return $insuranceReceivable->refresh();
        });
    }

    private function amount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'amount' => 'Receivable formation amount is required.',
            ]);
        }

        return (string) $amount;
    }
}
