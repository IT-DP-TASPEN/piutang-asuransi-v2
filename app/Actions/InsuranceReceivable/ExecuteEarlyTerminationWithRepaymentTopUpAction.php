<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

class ExecuteEarlyTerminationWithRepaymentTopUpAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly ExecuteEarlyTerminationRepaymentTopUpAction $topUpAction,
        private readonly ExecuteEarlyTerminationAction $earlyTerminationAction,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        ?User $user = null,
    ): ?EarlyTerminationTransaction {
        if ($this->successfulTopUpExists($insuranceReceivable)) {
            return $this->earlyTerminationAction->handle($insuranceReceivable, $user);
        }

        $account = trim((string) $insuranceReceivable->saving_account_for_loan_repayment);

        if ($account === '') {
            $this->stopForManualExecution(
                $insuranceReceivable,
                $user,
                'Manual Early Termination execution required because repayment saving account is empty.',
                'empty_repayment_account',
            );

            return null;
        }

        if (str_contains(strtoupper($account), 'OPER')) {
            $this->stopForManualExecution(
                $insuranceReceivable,
                $user,
                'Manual Early Termination execution required for OPER account.',
                'oper_account',
            );

            return null;
        }

        $balanceResult = $this->coreBankingClient->inquireBalance($account, $insuranceReceivable, $user);
        $apiLog = $balanceResult['log_id'] === null
            ? null
            : ApiIntegrationLog::query()->find($balanceResult['log_id']);

        if (! $balanceResult['ok']) {
            $message = $balanceResult['description']
                ?: $balanceResult['error_message']
                ?: 'Inquiry balance failed.';
            $this->stopForTopUpFailure($insuranceReceivable, $user, $message, $apiLog);

            return null;
        }

        try {
            $totalOutstanding = BigDecimal::of((string) $insuranceReceivable->loan_outstanding);
            $availableBalance = BigDecimal::of((string) ($balanceResult['data']['availableBalance'] ?? '0'));
            $topUpAmount = $totalOutstanding->minus($availableBalance);
        } catch (Throwable $exception) {
            $this->stopForTopUpFailure($insuranceReceivable, $user, $exception->getMessage(), $apiLog);

            return null;
        }

        if ($topUpAmount->isLessThanOrEqualTo('0')) {
            return $this->earlyTerminationAction->handle($insuranceReceivable, $user);
        }

        $formattedAmount = (string) $topUpAmount->toScale(2, RoundingMode::HalfUp);

        try {
            $transaction = $this->topUpAction->handle($insuranceReceivable, $formattedAmount, $user);
        } catch (Throwable $exception) {
            $this->stopForTopUpFailure($insuranceReceivable, $user, $exception->getMessage(), $apiLog);

            return null;
        }

        if ($transaction->status !== GlToGlTransaction::STATUS_SUCCESS) {
            $this->stopForTopUpFailure(
                $insuranceReceivable,
                $user,
                $transaction->response_description ?: 'GL-to-GL repayment top up failed.',
                $apiLog,
                $transaction,
            );

            return null;
        }

        $fromStatus = $insuranceReceivable->system_status;
        $insuranceReceivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED,
            'last_error_message' => null,
        ])->saveQuietly();

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'early_termination_top_up_executed',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_EXECUTED,
            description: 'GL-to-GL repayment top up executed.',
            metadata: [
                'gl_to_gl_transaction_id' => $transaction->id,
                'top_up_amount' => $transaction->request_payload['amount'] ?? $formattedAmount,
            ],
            actor: $user,
            triggeredByType: 'job',
        );

        return $this->earlyTerminationAction->handle($insuranceReceivable, $user);
    }

    private function successfulTopUpExists(InsuranceReceivable $insuranceReceivable): bool
    {
        return $insuranceReceivable->glToGlTransactions()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP)
            ->where('status', GlToGlTransaction::STATUS_SUCCESS)
            ->exists();
    }

    private function stopForManualExecution(
        InsuranceReceivable $insuranceReceivable,
        ?User $user,
        string $message,
        string $reason,
    ): void {
        $fromWorkflowStatus = $insuranceReceivable->workflow_status;
        $fromSystemStatus = $insuranceReceivable->system_status;
        $account = trim((string) $insuranceReceivable->saving_account_for_loan_repayment);

        $insuranceReceivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            'last_error_message' => $message,
        ])->saveQuietly();

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'early_termination_manual_execution_required',
            fromStatus: $fromSystemStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            description: $message,
            metadata: [
                'repayment_saving_account' => $account,
                'reason' => $reason,
                'previous_workflow_status' => $fromWorkflowStatus,
                'previous_system_status' => $fromSystemStatus,
            ],
            actor: $user,
            triggeredByType: 'job',
        );
    }

    private function stopForTopUpFailure(
        InsuranceReceivable $insuranceReceivable,
        ?User $user,
        string $message,
        ?ApiIntegrationLog $apiLog = null,
        ?GlToGlTransaction $transaction = null,
    ): void {
        $fromStatus = $insuranceReceivable->system_status;
        $insuranceReceivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            'last_error_message' => $message,
        ])->saveQuietly();

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'early_termination_top_up_failed',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            description: $message,
            metadata: $transaction instanceof GlToGlTransaction
                ? ['gl_to_gl_transaction_id' => $transaction->id]
                : [],
            actor: $user,
            apiLog: $apiLog,
            triggeredByType: 'job',
        );
    }
}
