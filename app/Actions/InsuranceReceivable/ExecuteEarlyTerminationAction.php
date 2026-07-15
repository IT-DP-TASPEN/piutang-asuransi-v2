<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\EarlyTerminationTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\CoreBusinessPayloadComparator;
use App\Services\CoreBanking\CoreTransactionReferenceGenerator;
use App\Services\CoreBanking\CoreTransactionReferenceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteEarlyTerminationAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly CoreTransactionReferenceGenerator $referenceGenerator,
        private readonly CoreTransactionReferenceRegistry $referenceRegistry,
        private readonly CoreBusinessPayloadComparator $payloadComparator,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, ?User $user = null): EarlyTerminationTransaction
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot enter Early Termination.',
            ]);
        }

        if ($insuranceReceivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Terminal receivables cannot execute early termination.',
            ]);
        }

        if (
            $insuranceReceivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING
        ) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Early termination requires receivable formed status.',
            ]);
        }

        $operationKey = "ir:{$insuranceReceivable->id}:early-termination";
        $lock = Cache::lock("core-operation:{$operationKey}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'early_termination' => 'Early Termination Core operation is already being processed.',
            ]);
        }

        try {
            $validationPayload = $this->payloadFor($insuranceReceivable, '__REFERENCE__');
            $latest = $this->latestTransaction($insuranceReceivable, $operationKey);

            if ($latest instanceof EarlyTerminationTransaction) {
                if ($latest->status === EarlyTerminationTransaction::STATUS_SUCCESS
                    || $latest->resolution_outcome === EarlyTerminationTransaction::RESOLUTION_OUTCOME_POSTED) {
                    return $latest;
                }

                if ($latest->status === EarlyTerminationTransaction::STATUS_UNKNOWN_TIMEOUT
                    || ($latest->resolution_status === EarlyTerminationTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                        && $latest->resolution_outcome !== EarlyTerminationTransaction::RESOLUTION_OUTCOME_NOT_POSTED)) {
                    throw ValidationException::withMessages([
                        'early_termination' => 'Previous Early Termination attempt requires reconciliation before retry.',
                    ]);
                }

                if ($latest->status === EarlyTerminationTransaction::STATUS_FAILED
                    && is_array($latest->request_payload)
                    && ! $this->payloadComparator->same($latest->request_payload, $validationPayload)) {
                    $this->markReconciliationRequired($latest, 'Current Early Termination payload differs from failed attempt payload.');

                    throw ValidationException::withMessages([
                        'early_termination' => 'Current Early Termination payload differs from failed attempt payload.',
                    ]);
                }
            }

            $transaction = $this->createAttempt($insuranceReceivable, $operationKey, $user);
            $payload = $transaction->request_payload ?: $this->payloadFor($insuranceReceivable, $transaction->trx_reference);

            if ($transaction->request_payload === null) {
                $transaction->forceFill(['request_payload' => $payload])->save();
            }

            $result = $this->coreBankingClient->earlyTerminateLoan($payload, $transaction, $user);
            $data = $result['data'];
            $isSuccess = $result['response_code'] === '00';
            $isUnknown = $this->isUnknownResult($result);

            return DB::transaction(function () use ($insuranceReceivable, $transaction, $result, $data, $user, $isSuccess, $isUnknown): EarlyTerminationTransaction {
                $transaction->forceFill([
                    'response_payload' => [
                        'status' => $result['status'],
                        'response_code' => $result['response_code'],
                        'description' => $result['description'],
                        'data' => $data,
                        'raw_body' => $result['raw_body'],
                        'log_id' => $result['log_id'],
                        'error_message' => $result['error_message'],
                    ],
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'] ?: $result['error_message'],
                    'transaction_id' => $this->stringValue($data['transactionId'] ?? null),
                    'journal_id' => $this->stringValue($data['journalId'] ?? null),
                    'core_trx_reference' => $this->stringValue($data['trxReference'] ?? null),
                    'alternate_number' => $this->stringValue($data['alternateNumber'] ?? null),
                    'status' => $isSuccess
                        ? EarlyTerminationTransaction::STATUS_SUCCESS
                        : ($isUnknown ? EarlyTerminationTransaction::STATUS_UNKNOWN_TIMEOUT : EarlyTerminationTransaction::STATUS_FAILED),
                    'resolution_status' => $isUnknown ? EarlyTerminationTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED : null,
                    'resolution_outcome' => $isUnknown ? EarlyTerminationTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN : null,
                    'resolution_reason' => $isUnknown ? ($result['description'] ?: $result['error_message']) : null,
                    'executed_by' => $user?->id,
                    'executed_at' => now(),
                ])->save();

                if ($isSuccess) {
                    $insuranceReceivable->forceFill([
                        'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
                    ])->save();
                }

                return $transaction->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function createAttempt(InsuranceReceivable $insuranceReceivable, string $operationKey, ?User $user): EarlyTerminationTransaction
    {
        try {
            return DB::transaction(function () use ($insuranceReceivable, $operationKey, $user): EarlyTerminationTransaction {
                $attempts = $insuranceReceivable->earlyTerminationTransactions()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->get();

                if ($attempts->contains(fn (EarlyTerminationTransaction $attempt): bool => $attempt->status === EarlyTerminationTransaction::STATUS_SUCCESS)) {
                    return $attempts->first(fn (EarlyTerminationTransaction $attempt): bool => $attempt->status === EarlyTerminationTransaction::STATUS_SUCCESS)->refresh();
                }

                $latest = $attempts->sortByDesc('id')->first();

                if ($latest instanceof EarlyTerminationTransaction
                    && ($latest->status === EarlyTerminationTransaction::STATUS_UNKNOWN_TIMEOUT
                        || ($latest->resolution_status === EarlyTerminationTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                            && $latest->resolution_outcome !== EarlyTerminationTransaction::RESOLUTION_OUTCOME_NOT_POSTED))) {
                    throw ValidationException::withMessages([
                        'early_termination' => 'Previous Early Termination attempt requires reconciliation before retry.',
                    ]);
                }

                $attemptNo = ((int) $attempts->max('attempt_no')) + 1;
                $reference = $this->referenceGenerator->earlyTermination($insuranceReceivable->id, $attemptNo);
                $reservation = $this->referenceRegistry->reserve(
                    reference: $reference,
                    serviceAction: 'early_termination',
                    operationKey: $operationKey,
                    user: $user,
                );
                $transaction = $insuranceReceivable->earlyTerminationTransactions()->create([
                    'operation_key' => $operationKey,
                    'attempt_no' => $attemptNo,
                    'trx_reference' => $reference,
                    'request_payload' => $this->payloadFor($insuranceReceivable, $reference),
                    'status' => EarlyTerminationTransaction::STATUS_PENDING,
                    'executed_by' => $user?->id,
                ]);
                $this->referenceRegistry->link($reservation, $transaction);

                return $transaction;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'trx_reference' => 'Unable to generate unique early termination reference.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(InsuranceReceivable $insuranceReceivable, string $trxReference): array
    {
        if ($insuranceReceivable->loan_outstanding === null) {
            throw ValidationException::withMessages([
                'loan_outstanding' => 'Loan outstanding is required for early termination.',
            ]);
        }

        return [
            'trxReference' => $trxReference,
            'accountNumber' => $insuranceReceivable->loan_account_number,
            'altNumber' => $insuranceReceivable->alt_number ?? '',
            'principalPaid' => $this->apiMoneyNumber($insuranceReceivable->loan_outstanding),
            'interestPaid' => 0,
            'penaltyPaid' => 0,
            'principalWaive' => 0,
            'interestWaive' => 0,
            'description' => 'Pelunasan Debitur MD',
            'branchCode' => $insuranceReceivable->branch_code,
        ];
    }

    private function apiMoneyNumber(string $money): int|float
    {
        $normalized = str_replace(',', '', $money);

        if (preg_match('/^-?\d+\.00$/', $normalized) === 1 || preg_match('/^-?\d+$/', $normalized) === 1) {
            return (int) $normalized;
        }

        return (float) $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function latestTransaction(InsuranceReceivable $insuranceReceivable, string $operationKey): ?EarlyTerminationTransaction
    {
        return $insuranceReceivable->earlyTerminationTransactions()
            ->where(function ($query) use ($operationKey): void {
                $query->where('operation_key', $operationKey)
                    ->orWhereNull('operation_key');
            })
            ->latest('id')
            ->first();
    }

    private function markReconciliationRequired(EarlyTerminationTransaction $transaction, string $reason): void
    {
        $transaction->forceFill([
            'resolution_status' => EarlyTerminationTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED,
            'resolution_outcome' => EarlyTerminationTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
            'resolution_reason' => $reason,
        ])->save();
    }

    private function isUnknownResult(array $result): bool
    {
        return $result['status'] === null || $result['error_message'] !== null;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || $exception->getCode() === '23505'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
