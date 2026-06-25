<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\EarlyTerminationBalanceInquiry;
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

        $requestedAt = now();

        try {
            $balanceResult = $this->coreBankingClient->inquireBalance($account, $insuranceReceivable, $user);
        } catch (Throwable $exception) {
            $balanceInquiry = $this->createFailedBalanceInquiry(
                insuranceReceivable: $insuranceReceivable,
                account: $account,
                user: $user,
                requestedAt: $requestedAt,
                status: $this->statusForException($exception),
                errorMessage: $exception->getMessage(),
            );

            $this->stopForTopUpFailure($insuranceReceivable, $user, $exception->getMessage(), balanceInquiry: $balanceInquiry);

            return null;
        }

        $completedAt = now();
        $apiLog = $balanceResult['log_id'] === null
            ? null
            : ApiIntegrationLog::query()->find($balanceResult['log_id']);

        if (! $balanceResult['ok']) {
            $message = $balanceResult['description']
                ?: $balanceResult['error_message']
                ?: 'Inquiry balance failed.';
            $balanceInquiry = $this->createFailedBalanceInquiry(
                insuranceReceivable: $insuranceReceivable,
                account: $account,
                user: $user,
                requestedAt: $requestedAt,
                completedAt: $completedAt,
                apiLog: $apiLog,
                responseCode: $balanceResult['response_code'],
                responseDescription: $balanceResult['description'],
                status: $balanceResult['error_message'] !== null
                    ? $this->statusForErrorMessage($balanceResult['error_message'])
                    : EarlyTerminationBalanceInquiry::STATUS_FAILED,
                errorMessage: $balanceResult['error_message'],
            );
            $this->stopForTopUpFailure($insuranceReceivable, $user, $message, $apiLog, balanceInquiry: $balanceInquiry);

            return null;
        }

        try {
            $totalOutstanding = $this->decimalFromStoredAmount($insuranceReceivable->loan_outstanding, 'Loan outstanding');
            $availableBalance = $this->decimalFromApiAmount($balanceResult['data']['availableBalance'] ?? null);
            $rawTopUpAmount = $totalOutstanding->minus($availableBalance);
            $topUpAmount = $rawTopUpAmount->isLessThanOrEqualTo('0')
                ? BigDecimal::of('0')
                : $rawTopUpAmount;
        } catch (Throwable $exception) {
            $balanceInquiry = $this->createFailedBalanceInquiry(
                insuranceReceivable: $insuranceReceivable,
                account: $account,
                user: $user,
                requestedAt: $requestedAt,
                completedAt: $completedAt,
                apiLog: $apiLog,
                responseCode: $balanceResult['response_code'],
                responseDescription: $balanceResult['description'],
                status: EarlyTerminationBalanceInquiry::STATUS_PARSE_FAILED,
                errorMessage: $exception->getMessage(),
            );
            $this->stopForTopUpFailure($insuranceReceivable, $user, $exception->getMessage(), $apiLog, balanceInquiry: $balanceInquiry);

            return null;
        }

        $balanceInquiry = $this->createSuccessfulBalanceInquiry(
            insuranceReceivable: $insuranceReceivable,
            account: $account,
            user: $user,
            requestedAt: $requestedAt,
            completedAt: $completedAt,
            apiLog: $apiLog,
            responseCode: $balanceResult['response_code'],
            responseDescription: $balanceResult['description'],
            totalOutstanding: $totalOutstanding,
            availableBalance: $availableBalance,
            topUpAmount: $topUpAmount,
        );

        if ($topUpAmount->isLessThanOrEqualTo('0')) {
            return $this->earlyTerminationAction->handle($insuranceReceivable, $user);
        }

        $formattedAmount = (string) $topUpAmount->toScale(2, RoundingMode::HalfUp);

        try {
            $transaction = $this->topUpAction->handle($insuranceReceivable, $formattedAmount, $user, $balanceInquiry);
        } catch (Throwable $exception) {
            $this->stopForTopUpFailure($insuranceReceivable, $user, $exception->getMessage(), $apiLog, balanceInquiry: $balanceInquiry);

            return null;
        }

        if ($transaction->status !== GlToGlTransaction::STATUS_SUCCESS) {
            $this->stopForTopUpFailure(
                $insuranceReceivable,
                $user,
                $transaction->response_description ?: 'GL-to-GL repayment top up failed.',
                $apiLog,
                $transaction,
                $balanceInquiry,
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
                'early_termination_balance_inquiry_id' => $balanceInquiry->id,
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

    private function createSuccessfulBalanceInquiry(
        InsuranceReceivable $insuranceReceivable,
        string $account,
        ?User $user,
        mixed $requestedAt,
        mixed $completedAt,
        ?ApiIntegrationLog $apiLog,
        ?string $responseCode,
        ?string $responseDescription,
        BigDecimal $totalOutstanding,
        BigDecimal $availableBalance,
        BigDecimal $topUpAmount,
    ): EarlyTerminationBalanceInquiry {
        return EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $insuranceReceivable->id,
            'api_integration_log_id' => $apiLog?->id,
            'saving_account_number' => $account,
            'loan_outstanding_amount' => (string) $totalOutstanding->toScale(2, RoundingMode::HalfUp),
            'available_balance' => (string) $availableBalance->toScale(2, RoundingMode::HalfUp),
            'required_top_up_amount' => (string) $topUpAmount->toScale(2, RoundingMode::HalfUp),
            'response_code' => $responseCode,
            'response_description' => $responseDescription,
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $user?->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt,
        ]);
    }

    private function createFailedBalanceInquiry(
        InsuranceReceivable $insuranceReceivable,
        string $account,
        ?User $user,
        mixed $requestedAt,
        string $status,
        ?string $errorMessage = null,
        mixed $completedAt = null,
        ?ApiIntegrationLog $apiLog = null,
        ?string $responseCode = null,
        ?string $responseDescription = null,
    ): EarlyTerminationBalanceInquiry {
        return EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $insuranceReceivable->id,
            'api_integration_log_id' => $apiLog?->id,
            'saving_account_number' => $account,
            'loan_outstanding_amount' => $this->normalizedLoanOutstanding($insuranceReceivable),
            'response_code' => $responseCode,
            'response_description' => $responseDescription,
            'status' => $status,
            'error_message' => $errorMessage,
            'requested_by' => $user?->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt ?? now(),
        ]);
    }

    private function decimalFromStoredAmount(mixed $value, string $label): BigDecimal
    {
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException("{$label} is required.");
        }

        return BigDecimal::of(str_replace(',', '', (string) $value));
    }

    private function decimalFromApiAmount(mixed $value): BigDecimal
    {
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException('Balance inquiry response does not include availableBalance.');
        }

        if (is_float($value)) {
            throw new \InvalidArgumentException('Balance inquiry availableBalance must be a decimal string.');
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException('Balance inquiry availableBalance must be numeric.');
        }

        return BigDecimal::of(str_replace(',', '', trim((string) $value)));
    }

    private function normalizedLoanOutstanding(InsuranceReceivable $insuranceReceivable): string
    {
        try {
            return (string) $this->decimalFromStoredAmount($insuranceReceivable->loan_outstanding, 'Loan outstanding')
                ->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            return '0.00';
        }
    }

    private function statusForException(Throwable $exception): string
    {
        return $this->statusForErrorMessage($exception->getMessage());
    }

    private function statusForErrorMessage(?string $message): string
    {
        $normalized = strtolower((string) $message);

        if (str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout')) {
            return EarlyTerminationBalanceInquiry::STATUS_TIMEOUT;
        }

        return EarlyTerminationBalanceInquiry::STATUS_FAILED;
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
        ?EarlyTerminationBalanceInquiry $balanceInquiry = null,
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
            metadata: [
                ...($transaction instanceof GlToGlTransaction ? ['gl_to_gl_transaction_id' => $transaction->id] : []),
                ...($balanceInquiry instanceof EarlyTerminationBalanceInquiry ? ['early_termination_balance_inquiry_id' => $balanceInquiry->id] : []),
            ],
            actor: $user,
            apiLog: $apiLog,
            triggeredByType: 'job',
        );
    }
}
