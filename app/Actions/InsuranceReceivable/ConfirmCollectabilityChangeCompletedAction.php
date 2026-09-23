<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmCollectabilityChangeCompletedAction
{
    public function __construct(
        private readonly PerformLoanInquiryAction $loanInquiryAction,
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        $this->assertConfirmable($insuranceReceivable, $user);

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'it_collectability_confirmation_attempted',
            description: 'IT requested fresh loan validation of collectability and branch OPER repayment account.',
            actor: $user,
        );

        $receivable = $this->loanInquiryAction->handle($insuranceReceivable, $user);
        $apiLog = $this->latestApiLog($receivable);

        $expectedOper = null;
        $branchError = null;
        try {
            $expectedOper = $this->operAccount->expected($receivable);
        } catch (ValidationException $exception) {
            $branchError = collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
        }
        $actualOper = $this->operAccount->actual($receivable);
        $collectability = trim((string) $receivable->collectability);
        $freshValues = [
            'fresh_collectability' => $collectability,
            'expected_oper_account' => $expectedOper,
            'actual_repayment_account' => $receivable->saving_account_for_loan_repayment,
        ];

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'it_fresh_loan_validation_completed',
            description: 'Fresh loan data received for IT collectability and repayment account confirmation.',
            metadata: $freshValues,
            actor: $user,
            apiLog: $apiLog,
        );

        $errors = [];
        if ($collectability !== '5') {
            $errors['collectability'] = 'Fresh collectability must be 5; actual: '.($collectability === '' ? '(empty)' : $collectability).'.';
        }
        if ($branchError !== null || $actualOper !== $expectedOper) {
            $errors['saving_account_for_loan_repayment'] = $branchError
                ?? "Fresh repayment account must be changed to branch OPER {$expectedOper}; actual: ".($actualOper === '' ? '(empty)' : $actualOper).'.';
        }

        if ($errors !== []) {
            $message = implode(' ', $errors);
            $receivable->forceFill(['last_error_message' => $message])->save();
            $this->stageLogger->log(
                receivable: $receivable,
                event: 'it_changes_confirmation_blocked',
                fromStatus: $receivable->workflow_status,
                toStatus: $receivable->workflow_status,
                description: $message,
                metadata: [
                    ...$freshValues,
                    'collectability_failed' => isset($errors['collectability']),
                    'oper_account_failed' => isset($errors['saving_account_for_loan_repayment']),
                ],
                actor: $user,
                apiLog: $apiLog,
            );

            throw ValidationException::withMessages([count($errors) > 1 ? 'it_changes' : array_key_first($errors) => $message]);
        }

        return DB::transaction(function () use ($receivable, $user, $freshValues, $apiLog): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertConfirmable($locked, $user);

            $approvalRequest = $this->approvalService->submit(
                approvable: $locked,
                workflowCode: ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
                actor: $user,
                notes: 'Fresh collectability 5 and branch OPER repayment account validated by IT.',
                metadata: $freshValues,
            );

            $fromStatus = $locked->workflow_status;
            $locked->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
                'last_error_message' => null,
            ])->save();

            $this->stageLogger->log(
                receivable: $locked,
                event: 'collectability_change_confirmed',
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                description: 'IT verified fresh collectability 5 and branch OPER repayment account. Accounting approval requested.',
                metadata: $freshValues,
                actor: $user,
                approvalRequest: $approvalRequest,
                apiLog: $apiLog,
            );

            return $locked->refresh();
        });
    }

    private function assertConfirmable(InsuranceReceivable $receivable, User $user): void
    {
        if (! $user->can('confirmCollectabilityChange', $receivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized IT users can confirm collectability changes.',
            ]);
        }

        if ($receivable->isLegacyOrigin() || $receivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'This receivable cannot enter formation validation.',
            ]);
        }

        if ($receivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Receivable is not waiting for collectability confirmation.',
            ]);
        }
    }

    private function latestApiLog(InsuranceReceivable $receivable): ?ApiIntegrationLog
    {
        return ApiIntegrationLog::query()
            ->where('related_type', $receivable->getMorphClass())
            ->where('related_id', $receivable->getKey())
            ->latest('id')
            ->first();
    }
}
