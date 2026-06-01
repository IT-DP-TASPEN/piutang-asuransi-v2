<?php

namespace App\Actions\CkpnJournal;

use App\Models\CkpnJournal;
use App\Models\GlToGlTransaction;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\PayloadBuilders\GlToGlPayloadBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteGlToGlTransferAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly GlToGlPayloadBuilder $payloadBuilder,
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

        $transaction = DB::transaction(fn(): GlToGlTransaction => $this->findOrCreateTransaction($journal, $user));
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

        return DB::transaction(function () use ($transaction, $result, $user, $isSuccess): GlToGlTransaction {
            $transaction->forceFill([
                'response_payload' => [
                    'status' => $result['status'],
                    'response_code' => $result['response_code'],
                    'description' => $result['description'],
                    'data' => $result['data'],
                    'raw_body' => $result['raw_body'],
                    'log_id' => $result['log_id'],
                ],
                'response_code' => $result['response_code'],
                'response_description' => $result['description'],
                'status' => $isSuccess ? GlToGlTransaction::STATUS_SUCCESS : GlToGlTransaction::STATUS_FAILED,
                'executed_by' => $user?->id,
                'executed_at' => now(),
            ])->save();

            return $transaction->refresh();
        });
    }

    private function findOrCreateTransaction(CkpnJournal $journal, ?User $user): GlToGlTransaction
    {
        $existing = $journal->glToGlTransactions()
            ->where(fn($query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', GlToGlTransaction::STATUS_SUCCESS))
            ->latest('id')
            ->first();

        if ($existing instanceof GlToGlTransaction) {
            return $existing;
        }

        for ($seconds = 0; $seconds < 10; $seconds++) {
            $timestamp = now()->copy()->addSeconds($seconds)->format('YmHi');
            $referenceNumber = "{$timestamp}";
            $receiptNumber = "{$timestamp}";

            try {
                return $journal->glToGlTransactions()->create([
                    'ckpn_workpaper_id' => $journal->ckpn_workpaper_id,
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $receiptNumber,
                    'request_payload' => $this->payloadBuilder->build($journal, $referenceNumber, $receiptNumber),
                    'status' => GlToGlTransaction::STATUS_PENDING,
                    'executed_by' => $user?->id,
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23000' && ! str_contains($exception->getMessage(), 'UNIQUE')) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages([
            'reference_number' => 'Unable to generate unique GL-to-GL references.',
        ]);
    }
}
