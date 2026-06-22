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
        bool $manualTopUpConfirmed = false,
    ): ?EarlyTerminationTransaction {
        if ($manualTopUpConfirmed || $this->successfulTopUpExists($insuranceReceivable)) {
            return $this->earlyTerminationAction->handle($insuranceReceivable, $user);
        }

        $account = trim((string) $insuranceReceivable->saving_account_for_loan_repayment);

        if ($account === '') {
            $this->stopForManualTopUp(
                $insuranceReceivable,
                $user,
                'Manual top up required because repayment saving account is empty.',
            );

            return null;
        }

        if (str_contains(strtoupper($account), 'OPER')) {
            $this->stopForManualTopUp(
                $insuranceReceivable,
                $user,
                'Manual top up required for OPER account.',
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

    private function stopForManualTopUp(
        InsuranceReceivable $insuranceReceivable,
        ?User $user,
        string $message,
    ): void {
        $fromStatus = $insuranceReceivable->system_status;
        $insuranceReceivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
            'last_error_message' => $message,
        ])->saveQuietly();

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'early_termination_manual_top_up_required',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
            description: $message,
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
