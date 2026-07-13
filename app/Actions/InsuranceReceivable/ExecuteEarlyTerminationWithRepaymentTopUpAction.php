<?php

namespace App\Actions\InsuranceReceivable;

use App\Data\EarlyTerminationSplitTopUpResult;
use App\Models\ApiIntegrationLog;
use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\CalculateEarlyTerminationSplitTopUp;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExecuteEarlyTerminationWithRepaymentTopUpAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly ExecuteEarlyTerminationRepaymentTopUpAction $topUpAction,
        private readonly ExecuteEarlyTerminationAction $earlyTerminationAction,
        private readonly CalculateEarlyTerminationSplitTopUp $splitCalculator,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        ?User $user = null,
    ): ?EarlyTerminationTransaction {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter Early Termination.',
            ]);
        }

        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:early-termination-top-up", 300);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'early_termination' => 'Early Termination is already being processed.',
            ]);
        }

        try {
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

            $loan = $this->freshLoanInquiry($insuranceReceivable, $user);
            $fincloudOutstanding = $this->moneyDecimal($loan['data']['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding');
            $receivable = $this->storeEtLoanSnapshot($insuranceReceivable, $loan['data']);
            $account = trim((string) $receivable->saving_account_for_loan_repayment);
            $contractOutstanding = $this->contractOutstanding($receivable, $fincloudOutstanding);
            $preTopUpContext = $this->hasTopUpComponent($receivable)
                ? EarlyTerminationBalanceInquiry::CONTEXT_RETRY_PRE_TOP_UP
                : EarlyTerminationBalanceInquiry::CONTEXT_PRE_TOP_UP;

            $preTopUp = $this->successfulBalanceInquiry(
                receivable: $receivable,
                account: $account,
                user: $user,
                fincloudOutstanding: $fincloudOutstanding,
                context: $preTopUpContext,
            );

            if (! $preTopUp instanceof EarlyTerminationBalanceInquiry) {
                return null;
            }

            $split = $this->splitFromInquiry($receivable, $preTopUp, $fincloudOutstanding, $contractOutstanding);
            $this->logSplit($receivable, $split, $preTopUp, $user);

            if (! $this->processComponent(
                receivable: $receivable,
                purpose: GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                amount: $split->lsaTopUpAmount,
                user: $user,
                inquiry: $preTopUp,
            )) {
                return null;
            }

            $preContract = $this->successfulBalanceInquiry(
                receivable: $receivable->refresh(),
                account: $account,
                user: $user,
                fincloudOutstanding: $fincloudOutstanding,
                context: EarlyTerminationBalanceInquiry::CONTEXT_PRE_CONTRACT_TOP_UP,
            );

            if (! $preContract instanceof EarlyTerminationBalanceInquiry) {
                return null;
            }

            $splitAfterLsa = $this->splitFromInquiry($receivable->refresh(), $preContract, $fincloudOutstanding, $contractOutstanding);
            $this->logSplit($receivable, $splitAfterLsa, $preContract, $user);

            if ($splitAfterLsa->lsaTopUpAmount->isGreaterThan('0')) {
                $this->markFlatSpreadStillRequired($receivable, $preContract, $user);

                return null;
            }

            if (! $this->processComponent(
                receivable: $receivable->refresh(),
                purpose: GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
                amount: $splitAfterLsa->piutangTopUpAmount,
                user: $user,
                inquiry: $preContract,
            )) {
                return null;
            }

            $postVerification = $this->successfulBalanceInquiry(
                receivable: $receivable->refresh(),
                account: $account,
                user: $user,
                fincloudOutstanding: $fincloudOutstanding,
                context: EarlyTerminationBalanceInquiry::CONTEXT_POST_TOP_UP_VERIFICATION,
            );

            if (! $postVerification instanceof EarlyTerminationBalanceInquiry) {
                return null;
            }

            $finalLoan = $this->freshLoanInquiry($receivable->refresh(), $user);
            $finalFincloudOutstanding = $this->moneyDecimal($finalLoan['data']['loanOutStanding'] ?? null, 'Final Fincloud outstanding');

            if (! $finalFincloudOutstanding->isEqualTo($fincloudOutstanding)) {
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    'Fresh loan outstanding changed before Early Termination. Reconciliation is required.',
                    balanceInquiry: $postVerification,
                );

                return null;
            }

            $available = $this->moneyDecimal($postVerification->available_balance, 'Post top up available balance');

            if ($available->isLessThan($fincloudOutstanding)) {
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    'Post top up verification balance is insufficient for Early Termination.',
                    balanceInquiry: $postVerification,
                );

                return null;
            }

            if ($this->hasBlockingComponent($receivable->refresh())) {
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    'Early Termination top up component is failed, unknown, or requires reconciliation.',
                    balanceInquiry: $postVerification,
                );

                return null;
            }

            $this->stageLogger->log(
                receivable: $receivable->refresh(),
                event: 'early_termination_final_balance_verified',
                description: 'Post top up balance and final loan state verified before Early Termination.',
                metadata: [
                    'early_termination_balance_inquiry_id' => $postVerification->id,
                    'available_balance' => (string) $available->toScale(2, RoundingMode::HalfUp),
                    'fincloud_outstanding' => (string) $fincloudOutstanding->toScale(2, RoundingMode::HalfUp),
                ],
                actor: $user,
                triggeredByType: 'job',
            );

            return $this->earlyTerminationAction->handle($receivable->refresh(), $user);
        } finally {
            $lock->release();
        }
    }

    private function processComponent(
        InsuranceReceivable $receivable,
        string $purpose,
        BigDecimal $amount,
        ?User $user,
        EarlyTerminationBalanceInquiry $inquiry,
    ): bool {
        $amount = $amount->toScale(2, RoundingMode::HalfUp);
        $existing = $this->componentTransaction($receivable, $purpose);

        if ($amount->isLessThanOrEqualTo('0')) {
            if ($existing instanceof GlToGlTransaction && ! $existing->isSatisfied()) {
                $this->markNoLongerRequired($existing, $user, $inquiry, 'Current recalculation no longer requires this top up component.');
            }

            $this->stageLogger->log(
                receivable: $receivable,
                event: $purpose.'_skipped',
                description: 'Early Termination top up component skipped because amount is zero.',
                metadata: [
                    'purpose' => $purpose,
                    'early_termination_balance_inquiry_id' => $inquiry->id,
                ],
                actor: $user,
                triggeredByType: 'job',
            );

            return true;
        }

        if ($existing instanceof GlToGlTransaction && $existing->isSatisfied()) {
            $this->markReconciliationRequired($existing, $user, $inquiry, 'Component was previously satisfied but current recalculation requires more funding.');
            $this->stopForTopUpFailure($receivable, $user, 'Early Termination top up component requires reconciliation.', balanceInquiry: $inquiry);

            return false;
        }

        if ($existing instanceof GlToGlTransaction) {
            if ($existing->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED) {
                $this->stopForTopUpFailure(
                    $receivable,
                    $user,
                    'Early Termination top up component requires reconciliation.',
                    transaction: $existing,
                    balanceInquiry: $inquiry,
                );

                return false;
            }

            $storedAmount = $this->storedPayloadAmount($existing);

            if (! $storedAmount instanceof BigDecimal) {
                $this->markReconciliationRequired($existing, $user, $inquiry, 'Stored GL payload amount is missing or malformed.');
                $this->stopForTopUpFailure($receivable, $user, 'Stored GL payload amount is missing or malformed.', transaction: $existing, balanceInquiry: $inquiry);

                return false;
            }

            if (! $storedAmount->isEqualTo($amount)) {
                $this->markReconciliationRequired($existing, $user, $inquiry, 'Current required amount differs from immutable GL payload amount.');
                $this->stopForTopUpFailure($receivable, $user, 'Current required amount differs from immutable GL payload amount.', transaction: $existing, balanceInquiry: $inquiry);

                return false;
            }
        }

        if ($purpose === GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP) {
            $trxType = trim((string) $receivable->contract_outstanding_trx_type);

            if ($trxType === '') {
                $this->stopForTopUpFailure($receivable, $user, 'Flat spread top up requires stored LSA transaction type.', balanceInquiry: $inquiry);

                return false;
            }

            $transaction = $this->topUpAction->handleFlatSpread(
                insuranceReceivable: $receivable,
                amount: (string) $amount,
                trxType: $trxType,
                user: $user,
                balanceInquiry: $inquiry,
            );
        } else {
            $transaction = $this->topUpAction->handleContract(
                insuranceReceivable: $receivable,
                amount: (string) $amount,
                user: $user,
                balanceInquiry: $inquiry,
            );
        }

        if (! $transaction->isSatisfied()) {
            $this->stopForTopUpFailure(
                $receivable,
                $user,
                $transaction->response_description ?: 'GL-to-GL repayment top up failed.',
                transaction: $transaction,
                balanceInquiry: $inquiry,
            );

            return false;
        }

        $this->stageLogger->log(
            receivable: $receivable,
            event: $purpose.'_succeeded',
            description: 'Early Termination top up component succeeded.',
            metadata: [
                'gl_to_gl_transaction_id' => $transaction->id,
                'early_termination_balance_inquiry_id' => $inquiry->id,
                'amount' => (string) $amount,
            ],
            actor: $user,
            triggeredByType: 'job',
        );

        return true;
    }

    private function freshLoanInquiry(InsuranceReceivable $receivable, ?User $user): array
    {
        $result = $this->coreBankingClient->inquireLoan(
            accountNumber: trim((string) $receivable->loan_account_number),
            related: $receivable,
            requestedBy: $user,
        );

        if ($result['response_code'] !== '00') {
            throw ValidationException::withMessages([
                'loan_account_number' => $result['description'] ?: $result['error_message'] ?: 'Fresh Fincloud loan inquiry failed.',
            ]);
        }

        return $result;
    }

    private function storeEtLoanSnapshot(InsuranceReceivable $receivable, array $data): InsuranceReceivable
    {
        return DB::transaction(function () use ($receivable, $data): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'loan_outstanding' => (string) $this->moneyDecimal($data['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding')->toScale(2, RoundingMode::HalfUp),
                'alt_number' => $this->stringValue($data['altNumber'] ?? null) ?? $locked->alt_number,
                'saving_account_for_loan_repayment' => $this->stringValue($data['saForLoanRepayment'] ?? null) ?? $locked->saving_account_for_loan_repayment,
                'inquiry_completed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function successfulBalanceInquiry(
        InsuranceReceivable $receivable,
        string $account,
        ?User $user,
        BigDecimal $fincloudOutstanding,
        string $context,
    ): ?EarlyTerminationBalanceInquiry {
        $requestedAt = now();
        $result = $this->coreBankingClient->inquireBalance($account, $receivable, $user);
        $completedAt = now();
        $apiLog = $result['log_id'] === null ? null : ApiIntegrationLog::query()->find($result['log_id']);

        if (! $result['ok']) {
            $message = $result['description'] ?: $result['error_message'] ?: 'Inquiry balance failed.';
            $inquiry = $this->createFailedBalanceInquiry(
                insuranceReceivable: $receivable,
                account: $account,
                user: $user,
                requestedAt: $requestedAt,
                completedAt: $completedAt,
                apiLog: $apiLog,
                responseCode: $result['response_code'],
                responseDescription: $result['description'],
                status: $this->statusForErrorMessage($result['error_message']),
                errorMessage: $result['error_message'],
                context: $context,
                fincloudOutstanding: $fincloudOutstanding,
            );
            $this->stopForTopUpFailure($receivable, $user, $message, $apiLog, balanceInquiry: $inquiry);

            return null;
        }

        try {
            $available = $this->moneyDecimal($result['data']['availableBalance'] ?? null, 'Balance inquiry availableBalance');
            $required = $this->max($fincloudOutstanding->minus($available), '0');
        } catch (Throwable $exception) {
            $inquiry = $this->createFailedBalanceInquiry(
                insuranceReceivable: $receivable,
                account: $account,
                user: $user,
                requestedAt: $requestedAt,
                completedAt: $completedAt,
                apiLog: $apiLog,
                responseCode: $result['response_code'],
                responseDescription: $result['description'],
                status: EarlyTerminationBalanceInquiry::STATUS_PARSE_FAILED,
                errorMessage: $exception->getMessage(),
                context: $context,
                fincloudOutstanding: $fincloudOutstanding,
            );
            $this->stopForTopUpFailure($receivable, $user, $exception->getMessage(), $apiLog, balanceInquiry: $inquiry);

            return null;
        }

        return EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'api_integration_log_id' => $apiLog?->id,
            'context' => $context,
            'saving_account_number' => $account,
            'loan_outstanding_amount' => (string) $fincloudOutstanding->toScale(2, RoundingMode::HalfUp),
            'available_balance' => (string) $available->toScale(2, RoundingMode::HalfUp),
            'required_top_up_amount' => (string) $required->toScale(2, RoundingMode::HalfUp),
            'response_code' => $result['response_code'],
            'response_description' => $result['description'],
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $user?->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt,
        ]);
    }

    private function splitFromInquiry(
        InsuranceReceivable $receivable,
        EarlyTerminationBalanceInquiry $inquiry,
        BigDecimal $fincloudOutstanding,
        BigDecimal $contractOutstanding,
    ): EarlyTerminationSplitTopUpResult {
        $split = $this->splitCalculator->handle(
            fincloudOutstanding: $fincloudOutstanding,
            contractOutstanding: $contractOutstanding,
            availableBalance: $inquiry->available_balance,
        );

        $inquiry->forceFill([
            'contract_outstanding_amount' => (string) $split->contractOutstanding->toScale(2, RoundingMode::HalfUp),
            'spread_amount' => (string) $split->spread->toScale(2, RoundingMode::HalfUp),
            'total_shortage_amount' => (string) $split->totalShortage->toScale(2, RoundingMode::HalfUp),
            'lsa_top_up_amount' => (string) $split->lsaTopUpAmount->toScale(2, RoundingMode::HalfUp),
            'piutang_top_up_amount' => (string) $split->piutangTopUpAmount->toScale(2, RoundingMode::HalfUp),
        ])->save();

        return $split;
    }

    private function contractOutstanding(InsuranceReceivable $receivable, BigDecimal $fincloudOutstanding): BigDecimal
    {
        if ($receivable->contract_outstanding_amount === null) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding snapshot is required for automated Early Termination.',
            ]);
        }

        $contract = $this->moneyDecimal($receivable->contract_outstanding_amount, 'Contract Outstanding snapshot');

        if ($contract->isLessThanOrEqualTo('0')) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding snapshot must be greater than zero.',
            ]);
        }

        if ($contract->isGreaterThan($fincloudOutstanding)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding snapshot cannot exceed fresh Fincloud outstanding.',
            ]);
        }

        return $contract;
    }

    private function createFailedBalanceInquiry(
        InsuranceReceivable $insuranceReceivable,
        string $account,
        ?User $user,
        mixed $requestedAt,
        mixed $completedAt,
        ?ApiIntegrationLog $apiLog,
        ?string $responseCode,
        ?string $responseDescription,
        string $status,
        ?string $errorMessage,
        string $context,
        BigDecimal $fincloudOutstanding,
    ): EarlyTerminationBalanceInquiry {
        return EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $insuranceReceivable->id,
            'api_integration_log_id' => $apiLog?->id,
            'context' => $context,
            'saving_account_number' => $account,
            'loan_outstanding_amount' => (string) $fincloudOutstanding->toScale(2, RoundingMode::HalfUp),
            'response_code' => $responseCode,
            'response_description' => $responseDescription,
            'status' => $status,
            'error_message' => $errorMessage,
            'requested_by' => $user?->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt,
        ]);
    }

    private function componentTransaction(InsuranceReceivable $receivable, string $purpose): ?GlToGlTransaction
    {
        return $receivable->glToGlTransactions()
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();
    }

    private function storedPayloadAmount(GlToGlTransaction $transaction): ?BigDecimal
    {
        $payload = $transaction->request_payload;
        $amount = is_array($payload) ? ($payload['amount'] ?? null) : null;

        if ($amount === null || $amount === '' || is_float($amount)) {
            return null;
        }

        try {
            return BigDecimal::of(str_replace(',', '', trim((string) $amount)))->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            return null;
        }
    }

    private function markNoLongerRequired(GlToGlTransaction $transaction, ?User $user, EarlyTerminationBalanceInquiry $inquiry, string $reason): void
    {
        DB::transaction(function () use ($transaction, $user, $inquiry, $reason): void {
            GlToGlTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED,
                    'resolution_reason' => $reason,
                    'resolution_payload' => ['early_termination_balance_inquiry_id' => $inquiry->id],
                    'resolved_by' => $user?->id,
                    'resolved_at' => now(),
                ])
                ->save();
        });

        $this->stageLogger->log(
            receivable: $transaction->insuranceReceivable,
            event: 'early_termination_top_up_no_longer_required',
            description: $reason,
            metadata: [
                'gl_to_gl_transaction_id' => $transaction->id,
                'early_termination_balance_inquiry_id' => $inquiry->id,
            ],
            actor: $user,
            triggeredByType: 'job',
        );
    }

    private function markReconciliationRequired(GlToGlTransaction $transaction, ?User $user, EarlyTerminationBalanceInquiry $inquiry, string $reason): void
    {
        DB::transaction(function () use ($transaction, $inquiry, $reason): void {
            GlToGlTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED,
                    'resolution_reason' => $reason,
                    'resolution_payload' => ['early_termination_balance_inquiry_id' => $inquiry->id],
                ])
                ->save();
        });

        $this->stageLogger->log(
            receivable: $transaction->insuranceReceivable,
            event: 'early_termination_top_up_reconciliation_required',
            description: $reason,
            metadata: [
                'gl_to_gl_transaction_id' => $transaction->id,
                'early_termination_balance_inquiry_id' => $inquiry->id,
            ],
            actor: $user,
            triggeredByType: 'job',
        );
    }

    private function markFlatSpreadStillRequired(InsuranceReceivable $receivable, EarlyTerminationBalanceInquiry $inquiry, ?User $user): void
    {
        $transaction = $this->componentTransaction($receivable, GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP);

        if ($transaction instanceof GlToGlTransaction) {
            $this->markReconciliationRequired($transaction, $user, $inquiry, 'Flat spread top up is still required after LSA step.');
        }

        $this->stopForTopUpFailure(
            $receivable,
            $user,
            'Flat spread top up is still required after LSA step. Piutang top up was not created.',
            transaction: $transaction,
            balanceInquiry: $inquiry,
        );
    }

    private function hasBlockingComponent(InsuranceReceivable $receivable): bool
    {
        return $receivable->glToGlTransactions()
            ->whereIn('purpose', [
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            ])
            ->where(function ($query): void {
                $query->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
                    ->orWhere(function ($query): void {
                        $query->whereIn('status', [
                            GlToGlTransaction::STATUS_PENDING,
                            GlToGlTransaction::STATUS_FAILED,
                            GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT,
                        ])->whereNotIn('resolution_status', [
                            GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED,
                            GlToGlTransaction::RESOLUTION_STATUS_RESOLVED_MANUALLY,
                        ]);
                    });
            })
            ->exists();
    }

    private function hasTopUpComponent(InsuranceReceivable $receivable): bool
    {
        return $receivable->glToGlTransactions()
            ->whereIn('purpose', [
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            ])
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

    private function logSplit(
        InsuranceReceivable $receivable,
        EarlyTerminationSplitTopUpResult $split,
        EarlyTerminationBalanceInquiry $inquiry,
        ?User $user,
    ): void {
        $this->stageLogger->log(
            receivable: $receivable,
            event: 'early_termination_split_calculated',
            description: 'Early Termination split top up calculated.',
            metadata: [
                'early_termination_balance_inquiry_id' => $inquiry->id,
                'fincloud_outstanding' => (string) $split->fincloudOutstanding,
                'contract_outstanding' => (string) $split->contractOutstanding,
                'available_balance' => (string) $split->availableBalance,
                'spread' => (string) $split->spread,
                'total_shortage' => (string) $split->totalShortage,
                'lsa_top_up_amount' => (string) $split->lsaTopUpAmount,
                'piutang_top_up_amount' => (string) $split->piutangTopUpAmount,
            ],
            actor: $user,
            triggeredByType: 'job',
        );
    }

    private function moneyDecimal(mixed $value, string $label): BigDecimal
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages(['amount' => "{$label} is required."]);
        }

        if (is_float($value)) {
            throw ValidationException::withMessages(['amount' => "{$label} must be a decimal string."]);
        }

        try {
            return BigDecimal::of(str_replace(',', '', trim((string) $value)))->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            throw ValidationException::withMessages(['amount' => "{$label} must be numeric."]);
        }
    }

    private function max(BigDecimal $left, string $right): BigDecimal
    {
        return $left->isLessThan($right) ? BigDecimal::of($right)->toScale(2) : $left->toScale(2, RoundingMode::HalfUp);
    }

    private function statusForErrorMessage(?string $message): string
    {
        $normalized = strtolower((string) $message);

        if (str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout')) {
            return EarlyTerminationBalanceInquiry::STATUS_TIMEOUT;
        }

        return EarlyTerminationBalanceInquiry::STATUS_FAILED;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }
}
