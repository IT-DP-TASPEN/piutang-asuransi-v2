<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\PayloadBuilders\EarlyTerminationRepaymentTopUpPayloadBuilder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteEarlyTerminationRepaymentTopUpAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly EarlyTerminationRepaymentTopUpPayloadBuilder $payloadBuilder,
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
            referenceNumber: "ETLSA-{$insuranceReceivable->id}",
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
            referenceNumber: "ETPIU-{$insuranceReceivable->id}",
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
        string $referenceNumber,
        ?User $user,
        ?EarlyTerminationBalanceInquiry $balanceInquiry,
    ): GlToGlTransaction {
        $amount = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $transaction = $this->findOrCreateTransaction(
            insuranceReceivable: $insuranceReceivable,
            amount: $amount,
            purpose: $purpose,
            trxType: $trxType,
            idempotencyKey: $idempotencyKey,
            referenceNumber: $referenceNumber,
            user: $user,
            balanceInquiry: $balanceInquiry,
        );

        if ($transaction->isSatisfied()) {
            return $transaction;
        }

        if ($transaction->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED) {
            throw ValidationException::withMessages([
                'gl_to_gl_transaction' => 'Early Termination top up requires reconciliation.',
            ]);
        }

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
                'resolution_reason' => $isUnknown ? $description : null,
                'executed_by' => $user?->id,
                'executed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function findOrCreateTransaction(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        string $purpose,
        string $trxType,
        string $idempotencyKey,
        string $referenceNumber,
        ?User $user,
        ?EarlyTerminationBalanceInquiry $balanceInquiry,
    ): GlToGlTransaction {
        try {
            return DB::transaction(function () use ($insuranceReceivable, $amount, $purpose, $trxType, $idempotencyKey, $referenceNumber, $user, $balanceInquiry): GlToGlTransaction {
                $existing = $this->transactionQuery($insuranceReceivable, $purpose, $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof GlToGlTransaction) {
                    return $existing;
                }

                $transaction = GlToGlTransaction::query()->create([
                    'purpose' => $purpose,
                    'insurance_receivable_id' => $insuranceReceivable->id,
                    'early_termination_balance_inquiry_id' => $balanceInquiry?->id,
                    'idempotency_key' => $idempotencyKey,
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
