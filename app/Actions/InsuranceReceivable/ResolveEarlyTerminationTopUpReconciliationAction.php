<?php

namespace App\Actions\InsuranceReceivable;

use App\Data\EarlyTerminationSplitTopUpResult;
use App\Models\ApiIntegrationLog;
use App\Models\EarlyTerminationBalanceInquiry;
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

class ResolveEarlyTerminationTopUpReconciliationAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly CalculateEarlyTerminationSplitTopUp $splitCalculator,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $receivable, string $purpose, User $user, ?string $notes = null): GlToGlTransaction
    {
        if (! in_array($purpose, [
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
        ], true)) {
            throw ValidationException::withMessages([
                'purpose' => 'Unsupported Early Termination top up component.',
            ]);
        }

        if (! $user->can('reconcileEarlyTerminationTopUp', $receivable)) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not allowed to reconcile this Early Termination top up.',
            ]);
        }

        if (trim((string) $notes) === '') {
            throw ValidationException::withMessages([
                'reconciliation' => 'Reconciliation notes are required.',
            ]);
        }

        $lock = Cache::lock("insurance-receivable:{$receivable->getKey()}:et-top-up-reconciliation", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Early Termination top up reconciliation is already being processed.',
            ]);
        }

        try {
            $transaction = $this->reconciliationTransaction($receivable, $purpose);
            $loan = $this->freshLoanInquiry($receivable, $user);
            $fincloud = $this->moneyDecimal($loan['data']['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding');
            $contract = $this->contractOutstanding($receivable, $fincloud);
            $balance = $this->balanceInquiry($receivable, $user, $fincloud);
            $split = $this->splitCalculator->handle($fincloud, $contract, $balance->available_balance);

            $this->storeSplit($balance, $split);
            $this->assertResolved($purpose, $split, $balance);

            return DB::transaction(function () use ($transaction, $purpose, $loan, $balance, $split, $user, $notes): GlToGlTransaction {
                $locked = GlToGlTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->resolution_status !== GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED) {
                    throw ValidationException::withMessages([
                        'reconciliation' => 'Early Termination top up component no longer requires reconciliation.',
                    ]);
                }

                $locked->forceFill([
                    'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_RESOLVED_MANUALLY,
                    'resolution_outcome' => GlToGlTransaction::RESOLUTION_OUTCOME_POSTED,
                    'resolution_reason' => 'Component funding condition verified.',
                    'resolution_payload' => [
                        'purpose' => $purpose,
                        'loan_api_log_id' => $loan['log_id'],
                        'early_termination_balance_inquiry_id' => $balance->id,
                        'fincloud_outstanding' => (string) $split->fincloudOutstanding,
                        'contract_outstanding' => (string) $split->contractOutstanding,
                        'available_balance' => (string) $split->availableBalance,
                        'spread' => (string) $split->spread,
                        'total_shortage' => (string) $split->totalShortage,
                        'lsa_top_up_amount' => (string) $split->lsaTopUpAmount,
                        'piutang_top_up_amount' => (string) $split->piutangTopUpAmount,
                    ],
                    'resolution_notes' => $notes,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                ])->save();

                $this->stageLogger->log(
                    receivable: $locked->insuranceReceivable,
                    event: 'early_termination_top_up_reconciliation_resolved',
                    description: $notes ?: 'Early Termination top up reconciliation verified.',
                    metadata: [
                        'gl_to_gl_transaction_id' => $locked->id,
                        'early_termination_balance_inquiry_id' => $balance->id,
                        'purpose' => $purpose,
                    ],
                    actor: $user,
                );

                return $locked->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function reconciliationTransaction(InsuranceReceivable $receivable, string $purpose): GlToGlTransaction
    {
        return DB::transaction(function () use ($receivable, $purpose): GlToGlTransaction {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isLegacyOrigin() || $locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Early Termination top up cannot be reconciled for this receivable.',
                ]);
            }

            $transaction = $locked->glToGlTransactions()
                ->where('purpose', $purpose)
                ->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $transaction instanceof GlToGlTransaction) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'No reconciliation-required component found.',
                ]);
            }

            return $transaction;
        });
    }

    private function freshLoanInquiry(InsuranceReceivable $receivable, User $user): array
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

    private function balanceInquiry(InsuranceReceivable $receivable, User $user, BigDecimal $fincloud): EarlyTerminationBalanceInquiry
    {
        $account = trim((string) $receivable->saving_account_for_loan_repayment);
        $requestedAt = now();
        $result = $this->coreBankingClient->inquireBalance($account, $receivable, $user);
        $completedAt = now();
        $apiLog = $result['log_id'] === null ? null : ApiIntegrationLog::query()->find($result['log_id']);

        if (! $result['ok']) {
            $inquiry = EarlyTerminationBalanceInquiry::query()->create([
                'insurance_receivable_id' => $receivable->id,
                'api_integration_log_id' => $apiLog?->id,
                'context' => EarlyTerminationBalanceInquiry::CONTEXT_RETRY_PRE_TOP_UP,
                'saving_account_number' => $account,
                'loan_outstanding_amount' => (string) $fincloud->toScale(2, RoundingMode::HalfUp),
                'response_code' => $result['response_code'],
                'response_description' => $result['description'],
                'status' => EarlyTerminationBalanceInquiry::STATUS_FAILED,
                'error_message' => $result['error_message'],
                'requested_by' => $user->id,
                'requested_at' => $requestedAt,
                'completed_at' => $completedAt,
            ]);

            throw ValidationException::withMessages([
                'balance' => $result['description'] ?: $result['error_message'] ?: "Balance inquiry failed. Inquiry #{$inquiry->id}",
            ]);
        }

        $available = $this->moneyDecimal($result['data']['availableBalance'] ?? null, 'Balance inquiry availableBalance');
        $required = $fincloud->minus($available);

        return EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'api_integration_log_id' => $apiLog?->id,
            'context' => EarlyTerminationBalanceInquiry::CONTEXT_RETRY_PRE_TOP_UP,
            'saving_account_number' => $account,
            'loan_outstanding_amount' => (string) $fincloud->toScale(2, RoundingMode::HalfUp),
            'available_balance' => (string) $available->toScale(2, RoundingMode::HalfUp),
            'required_top_up_amount' => (string) ($required->isLessThan('0') ? BigDecimal::of('0')->toScale(2) : $required->toScale(2, RoundingMode::HalfUp)),
            'response_code' => $result['response_code'],
            'response_description' => $result['description'],
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $user->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt,
        ]);
    }

    private function storeSplit(EarlyTerminationBalanceInquiry $inquiry, EarlyTerminationSplitTopUpResult $split): void
    {
        $inquiry->forceFill([
            'contract_outstanding_amount' => (string) $split->contractOutstanding->toScale(2, RoundingMode::HalfUp),
            'spread_amount' => (string) $split->spread->toScale(2, RoundingMode::HalfUp),
            'total_shortage_amount' => (string) $split->totalShortage->toScale(2, RoundingMode::HalfUp),
            'lsa_top_up_amount' => (string) $split->lsaTopUpAmount->toScale(2, RoundingMode::HalfUp),
            'piutang_top_up_amount' => (string) $split->piutangTopUpAmount->toScale(2, RoundingMode::HalfUp),
        ])->save();
    }

    private function assertResolved(string $purpose, EarlyTerminationSplitTopUpResult $split, EarlyTerminationBalanceInquiry $balance): void
    {
        if ($purpose === GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP) {
            if ($split->lsaTopUpAmount->isGreaterThan('0')) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'Flat spread component is not funded yet.',
                ]);
            }

            return;
        }

        if ($split->lsaTopUpAmount->isGreaterThan('0')
            || $split->piutangTopUpAmount->isGreaterThan('0')
            || BigDecimal::of((string) $balance->available_balance)->isLessThan($split->fincloudOutstanding)) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Contract component or final Early Termination funding condition is not satisfied.',
            ]);
        }
    }

    private function contractOutstanding(InsuranceReceivable $receivable, BigDecimal $fincloud): BigDecimal
    {
        $contract = $this->moneyDecimal($receivable->contract_outstanding_amount, 'Contract Outstanding snapshot');

        if ($contract->isLessThanOrEqualTo('0') || $contract->isGreaterThan($fincloud)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding snapshot is not consistent with fresh Fincloud outstanding.',
            ]);
        }

        return $contract;
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
}
