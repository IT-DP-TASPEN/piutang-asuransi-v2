<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\PayloadBuilders\EarlyTerminationRepaymentTopUpPayloadBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
    ): GlToGlTransaction {
        $transaction = $this->findOrCreateTransaction($insuranceReceivable, $amount, $user);

        if ($transaction->status === GlToGlTransaction::STATUS_SUCCESS) {
            return $transaction;
        }

        $payload = $transaction->request_payload;

        if (! is_array($payload)) {
            throw new \LogicException('Persisted early termination top up payload is missing.');
        }

        $result = $this->coreBankingClient->transferGlToGl($payload, $transaction, $user);
        $isSuccess = $result['response_code'] === '00';
        $description = $result['description'] ?: $result['error_message'];

        return DB::transaction(function () use ($transaction, $result, $user, $isSuccess, $description): GlToGlTransaction {
            $transaction->forceFill([
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
                'status' => $isSuccess ? GlToGlTransaction::STATUS_SUCCESS : GlToGlTransaction::STATUS_FAILED,
                'executed_by' => $user?->id,
                'executed_at' => now(),
            ])->save();

            return $transaction->refresh();
        });
    }

    private function findOrCreateTransaction(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        ?User $user,
    ): GlToGlTransaction {
        try {
            return DB::transaction(function () use ($insuranceReceivable, $amount, $user): GlToGlTransaction {
                $existing = $this->transactionQuery($insuranceReceivable)->lockForUpdate()->first();

                if ($existing instanceof GlToGlTransaction) {
                    return $existing;
                }

                $transaction = GlToGlTransaction::query()->create([
                    'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP,
                    'insurance_receivable_id' => $insuranceReceivable->id,
                    'status' => GlToGlTransaction::STATUS_PENDING,
                    'executed_by' => $user?->id,
                ]);
                $referenceNumber = "ETTOP{$transaction->id}";
                $payload = $this->payloadBuilder->build(
                    insuranceReceivable: $insuranceReceivable,
                    amount: $amount,
                    referenceNumber: $referenceNumber,
                    receiptNumber: $referenceNumber,
                );

                $transaction->forceFill([
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $referenceNumber,
                    'request_payload' => $payload,
                ])->save();

                return $transaction->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return $this->transactionQuery($insuranceReceivable)->firstOrFail();
        }
    }

    private function transactionQuery(InsuranceReceivable $insuranceReceivable): Builder
    {
        return GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP)
            ->where('insurance_receivable_id', $insuranceReceivable->id)
            ->latest('id');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || $exception->getCode() === '23505'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
