<?php

namespace App\Actions\CkpnJournal;

use App\Models\CkpnJournal;
use App\Models\GlToGlTransaction;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\CoreBusinessPayloadComparator;
use App\Services\CoreBanking\CoreTransactionReferenceGenerator;
use App\Services\CoreBanking\CoreTransactionReferenceRegistry;
use App\Services\CoreBanking\PayloadBuilders\GlToGlPayloadBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteGlToGlTransferAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly GlToGlPayloadBuilder $payloadBuilder,
        private readonly CoreTransactionReferenceGenerator $referenceGenerator,
        private readonly CoreTransactionReferenceRegistry $referenceRegistry,
        private readonly CoreBusinessPayloadComparator $payloadComparator,
    ) {}

    public function handle(CkpnJournal $journal, ?User $user = null): GlToGlTransaction
    {
        if (! in_array($journal->status, [
            CkpnJournal::STATUS_APPROVED,
            CkpnJournal::STATUS_GL_TO_GL_QUEUED,
            CkpnJournal::STATUS_GL_TO_GL_PROCESSING,
            CkpnJournal::STATUS_GL_TO_GL_FAILED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'GL-to-GL transfer requires an approved CKPN journal.',
            ]);
        }

        $operationKey = "ckpn:{$journal->id}:gl";
        $lock = Cache::lock("core-operation:{$operationKey}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'gl_to_gl_transaction' => 'CKPN GL-to-GL operation is already being processed.',
            ]);
        }

        try {
            $validationPayload = $this->payloadBuilder->build($journal, '__REFERENCE__', '__REFERENCE__');
            $latest = $this->latestTransaction($journal, $operationKey);

            if ($latest instanceof GlToGlTransaction) {
                if ($latest->status === GlToGlTransaction::STATUS_SUCCESS
                    || $latest->resolution_outcome === GlToGlTransaction::RESOLUTION_OUTCOME_POSTED) {
                    return $latest;
                }

                if ($latest->status === GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT
                    || ($latest->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                        && $latest->resolution_outcome !== GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED)) {
                    throw ValidationException::withMessages([
                        'gl_to_gl_transaction' => 'Previous CKPN GL-to-GL attempt requires reconciliation before retry.',
                    ]);
                }

                if ($latest->status === GlToGlTransaction::STATUS_FAILED
                    && is_array($latest->request_payload)
                    && ! $this->payloadComparator->same($latest->request_payload, $validationPayload)) {
                    $this->markReconciliationRequired($latest, 'Current CKPN GL-to-GL payload differs from failed attempt payload.');

                    throw ValidationException::withMessages([
                        'gl_to_gl_transaction' => 'Current CKPN GL-to-GL payload differs from failed attempt payload.',
                    ]);
                }
            }

            $transaction = $this->createAttempt($journal, $operationKey, $user);
            $payload = $transaction->request_payload ?: $this->payloadBuilder->build(
                journal: $journal,
                referenceNumber: $transaction->reference_number,
                receiptNumber: $transaction->receipt_number,
            );

            if ($transaction->request_payload === null) {
                $transaction->forceFill(['request_payload' => $payload])->save();
            }

            $result = $this->coreBankingClient->transferGlToGl($payload, $transaction, $user);
            $isSuccess = $result['response_code'] === '00';
            $isUnknown = $this->isUnknownResult($result);

            return DB::transaction(function () use ($transaction, $result, $user, $isSuccess, $isUnknown): GlToGlTransaction {
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
                    'response_description' => $result['description'] ?: $result['error_message'],
                    'status' => $isSuccess
                        ? GlToGlTransaction::STATUS_SUCCESS
                        : ($isUnknown ? GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT : GlToGlTransaction::STATUS_FAILED),
                    'resolution_status' => $isUnknown ? GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED : null,
                    'resolution_outcome' => $isUnknown ? GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN : null,
                    'resolution_reason' => $isUnknown ? ($result['description'] ?: $result['error_message']) : null,
                    'executed_by' => $user?->id,
                    'executed_at' => now(),
                ])->save();

                return $transaction->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function createAttempt(CkpnJournal $journal, string $operationKey, ?User $user): GlToGlTransaction
    {
        try {
            return DB::transaction(function () use ($journal, $operationKey, $user): GlToGlTransaction {
                $attempts = $journal->glToGlTransactions()
                    ->where('purpose', GlToGlTransaction::PURPOSE_CKPN_JOURNAL)
                    ->where('idempotency_key', $operationKey)
                    ->lockForUpdate()
                    ->get();

                if ($attempts->contains(fn (GlToGlTransaction $attempt): bool => $attempt->isSatisfied())) {
                    return $attempts->first(fn (GlToGlTransaction $attempt): bool => $attempt->isSatisfied())->refresh();
                }

                $latest = $attempts->sortByDesc('id')->first();

                if ($latest instanceof GlToGlTransaction
                    && ($latest->status === GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT
                        || ($latest->resolution_status === GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                            && $latest->resolution_outcome !== GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED))) {
                    throw ValidationException::withMessages([
                        'gl_to_gl_transaction' => 'Previous CKPN GL-to-GL attempt requires reconciliation before retry.',
                    ]);
                }

                $attemptNo = ((int) $attempts->max('attempt_no')) + 1;
                $referenceNumber = $this->referenceGenerator->ckpnJournal($journal->id, $attemptNo);
                $reservation = $this->referenceRegistry->reserve(
                    reference: $referenceNumber,
                    serviceAction: 'gl_to_gl:ckpn_journal',
                    operationKey: $operationKey,
                    user: $user,
                );
                $transaction = $journal->glToGlTransactions()->create([
                    'purpose' => GlToGlTransaction::PURPOSE_CKPN_JOURNAL,
                    'ckpn_workpaper_id' => $journal->ckpn_workpaper_id,
                    'idempotency_key' => $operationKey,
                    'attempt_no' => $attemptNo,
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $referenceNumber,
                    'request_payload' => $this->payloadBuilder->build($journal, $referenceNumber, $referenceNumber),
                    'status' => GlToGlTransaction::STATUS_PENDING,
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
                'reference_number' => 'Unable to generate unique GL-to-GL references.',
            ]);
        }
    }

    private function latestTransaction(CkpnJournal $journal, string $operationKey): ?GlToGlTransaction
    {
        return $journal->glToGlTransactions()
            ->where('purpose', GlToGlTransaction::PURPOSE_CKPN_JOURNAL)
            ->where(function ($query) use ($operationKey): void {
                $query->where('idempotency_key', $operationKey)
                    ->orWhereNull('idempotency_key');
            })
            ->latest('id')
            ->first();
    }

    private function markReconciliationRequired(GlToGlTransaction $transaction, string $reason): void
    {
        $transaction->forceFill([
            'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED,
            'resolution_outcome' => GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
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
