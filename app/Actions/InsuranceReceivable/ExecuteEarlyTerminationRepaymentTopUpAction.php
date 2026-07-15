<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\CoreBusinessPayloadComparator;
use App\Services\CoreBanking\CoreTransactionReferenceGenerator;
use App\Services\CoreBanking\CoreTransactionReferenceRegistry;
use App\Services\CoreBanking\PayloadBuilders\EarlyTerminationRepaymentTopUpPayloadBuilder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteEarlyTerminationRepaymentTopUpAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly EarlyTerminationRepaymentTopUpPayloadBuilder $payloadBuilder,
        private readonly CoreTransactionReferenceGenerator $referenceGenerator,
        private readonly CoreTransactionReferenceRegistry $referenceRegistry,
        private readonly CoreBusinessPayloadComparator $payloadComparator,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        ?User $user = null,
        ?EarlyTerminationBalanceInquiry $balanceInquiry = null,
    ): GlToGlTransaction {
        return $this->handleContract($insuranceReceivable, $amount, $user, $balanceInquiry);
    }

    public function handleFlatSpread(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        string $trxType,
        ?User $user = null,
        ?EarlyTerminationBalanceInquiry $balanceInquiry = null,
    ): GlToGlTransaction {
        return $this->handleComponent(
            insuranceReceivable: $insuranceReceivable,
            amount: $amount,
            purpose: GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            trxType: $trxType,
            idempotencyKey: "ir:{$insuranceReceivable->id}:et:flat-spread",
            user: $user,
            balanceInquiry: $balanceInquiry,
        );
    }

    public function handleContract(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        ?User $user = null,
        ?EarlyTerminationBalanceInquiry $balanceInquiry = null,
    ): GlToGlTransaction {
        return $this->handleComponent(
            insuranceReceivable: $insuranceReceivable,
            amount: $amount,
            purpose: GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            trxType: 'PiutangAsuransi',
            idempotencyKey: "ir:{$insuranceReceivable->id}:et:contract",
            user: $user,
            balanceInquiry: $balanceInquiry,
        );
    }

    private function handleComponent(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        string $purpose,
        string $trxType,
        string $idempotencyKey,
        ?User $user,
        ?EarlyTerminationBalanceInquiry $balanceInquiry,
    ): GlToGlTransaction {
        $lock = Cache::lock("core-operation:{$idempotencyKey}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'gl_to_gl_transaction' => 'Core GL-to-GL operation is already being processed.',
            ]);
        }

        try {
            $amount = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
            $latest = $this->latestTransaction($insuranceReceivable, $purpose, $idempotencyKey);
            $validationPayload = $this->payloadBuilder->build(
                insuranceReceivable: $insuranceReceivable,
                amount: $amount,
                referenceNumber: '__REFERENCE__',
                receiptNumber: '__REFERENCE__',
                trxType: $trxType,
            );

            if ($latest instanceof GlToGlTransaction) {
                if ($latest->isSatisfied()) {
                    return $latest;
                }

                if ($latest->status === GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT
                    || ($latest->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                        && $latest->resolution_outcome !== GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED)) {
                    throw ValidationException::withMessages([
                        'gl_to_gl_transaction' => 'Early Termination top up requires reconciliation before retry.',
                    ]);
                }

                if ($latest->status === GlToGlTransaction::STATUS_FAILED && is_array($latest->request_payload)) {
                    if (! $this->payloadComparator->same($latest->request_payload, $validationPayload)) {
                        $this->markReconciliationRequired($latest, 'Current Early Termination top up payload differs from failed attempt payload.');

                        throw ValidationException::withMessages([
                            'gl_to_gl_transaction' => 'Current Early Termination top up payload differs from failed attempt payload.',
                        ]);
                    }
                }
            }

            $transaction = $this->createAttempt(
                insuranceReceivable: $insuranceReceivable,
                amount: $amount,
                purpose: $purpose,
                trxType: $trxType,
                idempotencyKey: $idempotencyKey,
                user: $user,
                balanceInquiry: $balanceInquiry,
            );

            $payload = $transaction->request_payload;

            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'gl_to_gl_transaction' => 'Persisted Early Termination top up payload is missing.',
                ]);
            }

            $result = $this->coreBankingClient->transferGlToGl($payload, $transaction, $user);
            $isSuccess = $result['response_code'] === '00';
            $description = $result['description'] ?: $result['error_message'] ?: 'GL-to-GL repayment top up failed.';
            $isUnknown = $this->isUnknownResult($result);

            return DB::transaction(function () use ($transaction, $result, $user, $isSuccess, $description, $isUnknown): GlToGlTransaction {
                $locked = GlToGlTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->isSatisfied()) {
                    return $locked->refresh();
                }

                $locked->forceFill([
                    'response_payload' => [
                        'status' => $result['status'],
                        'response_code' => $result['response_code'],
                        'description' => $result['description'],
                        'data' => $result['data'],
                        'raw_body' => $result['raw_body'],
                        'log_id' => $result['log_id'],
                        'error_message' => $result['error_message'],
                    ],
                    'response_code' => $result['response_code'],
                    'response_description' => $description,
                    'status' => $isSuccess
                        ? GlToGlTransaction::STATUS_SUCCESS
                        : ($isUnknown ? GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT : GlToGlTransaction::STATUS_FAILED),
                    'resolution_status' => $isUnknown ? GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED : null,
                    'resolution_outcome' => $isUnknown ? GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN : null,
                    'resolution_reason' => $isUnknown ? $description : null,
                    'executed_by' => $user?->id,
                    'executed_at' => now(),
                ])->save();

                return $locked->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function createAttempt(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        string $purpose,
        string $trxType,
        string $idempotencyKey,
        ?User $user,
        ?EarlyTerminationBalanceInquiry $balanceInquiry,
    ): GlToGlTransaction {
        try {
            return DB::transaction(function () use ($insuranceReceivable, $amount, $purpose, $trxType, $idempotencyKey, $user, $balanceInquiry): GlToGlTransaction {
                $attempts = $this->transactionQuery($insuranceReceivable, $purpose, $idempotencyKey)
                    ->lockForUpdate()
                    ->get();

                if ($attempts->contains(fn (GlToGlTransaction $attempt): bool => $attempt->isSatisfied())) {
                    return $attempts->first(fn (GlToGlTransaction $attempt): bool => $attempt->isSatisfied())->refresh();
                }

                $latest = $attempts->first();

                if ($latest instanceof GlToGlTransaction
                    && ($latest->status === GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT
                        || ($latest->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                            && $latest->resolution_outcome !== GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED))) {
                    throw ValidationException::withMessages([
                        'gl_to_gl_transaction' => 'Previous Core attempt requires reconciliation before retry.',
                    ]);
                }

                $attemptNo = ((int) $attempts->max('attempt_no')) + 1;
                $referenceNumber = $this->referenceFor($purpose, $insuranceReceivable->id, $attemptNo);
                $reservation = $this->referenceRegistry->reserve(
                    reference: $referenceNumber,
                    serviceAction: 'gl_to_gl:'.$purpose,
                    operationKey: $idempotencyKey,
                    user: $user,
                );
                $transaction = GlToGlTransaction::query()->create([
                    'purpose' => $purpose,
                    'insurance_receivable_id' => $insuranceReceivable->id,
                    'early_termination_balance_inquiry_id' => $balanceInquiry?->id,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_no' => $attemptNo,
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $referenceNumber,
                    'status' => GlToGlTransaction::STATUS_PENDING,
                    'executed_by' => $user?->id,
                ]);

                $transaction->forceFill([
                    'request_payload' => $this->payloadBuilder->build(
                        insuranceReceivable: $insuranceReceivable,
                        amount: $amount,
                        referenceNumber: $referenceNumber,
                        receiptNumber: $referenceNumber,
                        trxType: $trxType,
                    ),
                ])->save();
                $this->referenceRegistry->link($reservation, $transaction);

                return $transaction->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->transactionQuery($insuranceReceivable, $purpose, $idempotencyKey)->first();

            if ($existing instanceof GlToGlTransaction) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function transactionQuery(InsuranceReceivable $insuranceReceivable, string $purpose, string $idempotencyKey): Builder
    {
        return GlToGlTransaction::query()
            ->where(function (Builder $query) use ($insuranceReceivable, $purpose, $idempotencyKey): void {
                $query->where('idempotency_key', $idempotencyKey)
                    ->orWhere(function (Builder $query) use ($insuranceReceivable, $purpose): void {
                        $query->where('purpose', $purpose)
                            ->where('insurance_receivable_id', $insuranceReceivable->id);
                    });
            })
            ->latest('id');
    }

    private function latestTransaction(InsuranceReceivable $insuranceReceivable, string $purpose, string $idempotencyKey): ?GlToGlTransaction
    {
        return $this->transactionQuery($insuranceReceivable, $purpose, $idempotencyKey)->first();
    }

    private function referenceFor(string $purpose, int $insuranceReceivableId, int $attemptNo): string
    {
        return $purpose === GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP
            ? $this->referenceGenerator->earlyTerminationFlatSpread($insuranceReceivableId, $attemptNo)
            : $this->referenceGenerator->earlyTerminationPiutang($insuranceReceivableId, $attemptNo);
    }

    private function markReconciliationRequired(GlToGlTransaction $transaction, string $reason): void
    {
        GlToGlTransaction::query()
            ->whereKey($transaction->getKey())
            ->update([
                'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED,
                'resolution_outcome' => GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
                'resolution_reason' => $reason,
            ]);
    }

    private function isUnknownResult(array $result): bool
    {
        if ($result['status'] === null) {
            return true;
        }

        $message = strtolower((string) $result['error_message']);

        return str_contains($message, 'timeout') || str_contains($message, 'timed out');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || $exception->getCode() === '23505'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
