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
use App\Services\InsuranceReceivable\OperRepaymentAccount;
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
        private readonly OperRepaymentAccount $operAccount,
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

        $operLock = null;

        try {
            try {
                $account = $this->operAccount->expected($insuranceReceivable);
                $this->operAccount->assertMatches($insuranceReceivable);
            } catch (ValidationException $exception) {
                $this->stopForTopUpFailure($insuranceReceivable, $user, $this->validationMessage($exception));

                return null;
            }

            $operLock = Cache::lock("insurance-receivable:oper:{$account}:early-termination", 300);

            if (! $operLock->get()) {
                throw ValidationException::withMessages([
                    'early_termination' => "Early Termination is already processing OPER account {$account}.",
                ]);
            }

            $loan = $this->freshLoanInquiry($insuranceReceivable, $user);
            $fincloudOutstanding = $this->moneyDecimal($loan['data']['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding');
            $receivable = $this->storeEtLoanSnapshot($insuranceReceivable, $loan['data']);

            if (! $this->operAccount->matches($receivable)) {
                $this->stopForTopUpFailure(
                    $receivable,
                    $user,
                    "Fresh repayment account must remain branch OPER {$account}; actual: ".($this->operAccount->actual($receivable) ?: '(empty)').'.',
                );

                return null;
            }

            $receivable->forceFill(['saving_account_for_loan_repayment' => $account])->save();
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

            if (! $this->processComponent(
                receivable: $receivable->refresh(),
                purpose: GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
                amount: $split->piutangTopUpAmount,
                user: $user,
                inquiry: $preTopUp,
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

            $available = $this->moneyDecimal($postVerification->available_balance, 'Post top up available balance');

            if ($available->isLessThan($fincloudOutstanding)) {
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    'Post top up OPER balance '.$available->toScale(2, RoundingMode::HalfUp)
                        .' is below funded Fincloud outstanding '.$fincloudOutstanding->toScale(2, RoundingMode::HalfUp).'.',
                    balanceInquiry: $postVerification,
                );

                return null;
            }

            $finalLoan = $this->freshLoanInquiry($receivable->refresh(), $user);
            $finalFincloudOutstanding = $this->moneyDecimal($finalLoan['data']['loanOutStanding'] ?? null, 'Final Fincloud outstanding');

            if ($this->operAccount->normalize($finalLoan['data']['saForLoanRepayment'] ?? null) !== $account) {
                $finalAccount = $this->operAccount->normalize($finalLoan['data']['saForLoanRepayment'] ?? null);
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    "Fresh repayment account changed before Early Termination; expected {$account}, actual ".($finalAccount ?: '(empty)').'. Reconciliation is required.',
                    balanceInquiry: $postVerification,
                );

                return null;
            }

            if (! $finalFincloudOutstanding->isEqualTo($fincloudOutstanding)) {
                $this->stopForTopUpFailure(
                    $receivable->refresh(),
                    $user,
                    'Fresh loan outstanding changed before Early Termination; funded for '
                        .$fincloudOutstanding->toScale(2, RoundingMode::HalfUp)
                        .', final fresh outstanding '.$finalFincloudOutstanding->toScale(2, RoundingMode::HalfUp)
                        .'. Reconciliation is required.',
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
                    'final_fresh_outstanding' => (string) $finalFincloudOutstanding->toScale(2, RoundingMode::HalfUp),
                ],
                actor: $user,
                triggeredByType: 'job',
            );

            return $this->earlyTerminationAction->handle($receivable->refresh(), $user);
        } finally {
            $operLock?->release();
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
        $blocking = $this->blockingComponentTransaction($receivable, $purpose);

        if ($blocking instanceof GlToGlTransaction) {
            $this->stopForTopUpFailure(
                $receivable,
                $user,
                'Early Termination top up component is pending, unknown, or requires reconciliation.',
                transaction: $blocking,
                balanceInquiry: $inquiry,
            );

            return false;
        }

        $satisfied = $this->fundedComponentTransaction($receivable, $purpose);

        if ($satisfied instanceof GlToGlTransaction) {
            $storedAmount = $this->storedPayloadAmount($satisfied);

            if (! $storedAmount instanceof BigDecimal || ! $storedAmount->isEqualTo($amount)) {
                $this->markReconciliationRequired($satisfied, $user, $inquiry, 'Current required amount differs from immutable satisfied GL payload amount.');
                $this->stopForTopUpFailure($receivable, $user, 'Early Termination top up component requires reconciliation.', transaction: $satisfied, balanceInquiry: $inquiry);

                return false;
            }

            $this->markSupersededFailedAttempts($receivable, $purpose, $satisfied, $user, $inquiry);

            $this->stageLogger->log(
                receivable: $receivable,
                event: $purpose.'_already_satisfied',
                description: 'Early Termination top up component already satisfied with the required immutable amount.',
                metadata: [
                    'gl_to_gl_transaction_id' => $satisfied->id,
                    'amount' => (string) $amount,
                ],
                actor: $user,
                triggeredByType: 'job',
            );

            return true;
        }

        if ($amount->isLessThanOrEqualTo('0')) {
            if ($existing instanceof GlToGlTransaction
                && $existing->resolution_status !== GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED) {
                $this->markNoLongerRequired($existing, $user, $inquiry, 'Current funding allocation no longer requires this top up component.');
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

        if ($existing instanceof GlToGlTransaction) {
            if ($existing->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED) {
                $this->markReconciliationRequired($existing, $user, $inquiry, 'A previously unnecessary component is now required by a changed funding allocation.');
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

        $this->markSupersededFailedAttempts($receivable, $purpose, $transaction, $user, $inquiry);

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
                'saving_account_for_loan_repayment' => $this->stringValue($data['saForLoanRepayment'] ?? null),
                'collectability' => $this->stringValue($data['collectability'] ?? null),
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
        );

        $inquiry->forceFill([
            'contract_outstanding_amount' => (string) $split->contractOutstanding->toScale(2, RoundingMode::HalfUp),
            'spread_amount' => (string) $split->spread->toScale(2, RoundingMode::HalfUp),
            'total_funding_amount' => (string) $split->totalFundingAmount->toScale(2, RoundingMode::HalfUp),
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

    private function blockingComponentTransaction(InsuranceReceivable $receivable, string $purpose): ?GlToGlTransaction
    {
        return $receivable->glToGlTransactions()
            ->where('purpose', $purpose)
            ->where(function ($query): void {
                $query->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
                    ->orWhereIn('status', [
                        GlToGlTransaction::STATUS_PENDING,
                        GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT,
                    ]);
            })
            ->latest('id')
            ->first();
    }

    private function fundedComponentTransaction(InsuranceReceivable $receivable, string $purpose): ?GlToGlTransaction
    {
        return $receivable->glToGlTransactions()
            ->where('purpose', $purpose)
            ->where(function ($query): void {
                $query->where('status', GlToGlTransaction::STATUS_SUCCESS)
                    ->orWhere('resolution_outcome', GlToGlTransaction::RESOLUTION_OUTCOME_POSTED);
            })
            ->latest('id')
            ->first();
    }

    private function markSupersededFailedAttempts(
        InsuranceReceivable $receivable,
        string $purpose,
        GlToGlTransaction $satisfied,
        ?User $user,
        EarlyTerminationBalanceInquiry $inquiry,
    ): void {
        $receivable->glToGlTransactions()
            ->where('purpose', $purpose)
            ->whereKeyNot($satisfied->getKey())
            ->where('status', GlToGlTransaction::STATUS_FAILED)
            ->where(function ($query): void {
                $query->whereNull('resolution_status')
                    ->orWhere('resolution_outcome', GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED);
            })
            ->get()
            ->each(fn (GlToGlTransaction $attempt) => $this->markNoLongerRequired(
                $attempt,
                $user,
                $inquiry,
                'Definitively failed GL attempt superseded by a successful retry for the same immutable amount.',
            ));
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
                    'resolution_outcome' => null,
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
                    'resolution_outcome' => GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
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
                'spread' => (string) $split->spread,
                'total_funding' => (string) $split->totalFundingAmount,
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

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
    }
}
