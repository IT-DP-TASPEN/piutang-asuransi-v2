<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\EarlyTerminationTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteEarlyTerminationAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
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

        if ($insuranceReceivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED
            && $insuranceReceivable->system_status !== InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Early termination requires receivable formed status.',
            ]);
        }

        $transaction = DB::transaction(fn (): EarlyTerminationTransaction => $this->findOrCreateTransaction($insuranceReceivable, $user));
        $payload = $transaction->request_payload ?: $this->payloadFor($insuranceReceivable, $transaction->trx_reference);

        if ($transaction->request_payload === null) {
            $transaction->forceFill(['request_payload' => $payload])->save();
        }

        $result = $this->coreBankingClient->earlyTerminateLoan($payload, $transaction, $user);
        $data = $result['data'];
        $isSuccess = $result['response_code'] === '00';

        return DB::transaction(function () use ($insuranceReceivable, $transaction, $result, $data, $user, $isSuccess): EarlyTerminationTransaction {
            $transaction->forceFill([
                'response_payload' => [
                    'status' => $result['status'],
                    'response_code' => $result['response_code'],
                    'description' => $result['description'],
                    'data' => $data,
                    'raw_body' => $result['raw_body'],
                    'log_id' => $result['log_id'],
                ],
                'response_code' => $result['response_code'],
                'response_description' => $result['description'],
                'transaction_id' => $this->stringValue($data['transactionId'] ?? null),
                'journal_id' => $this->stringValue($data['journalId'] ?? null),
                'core_trx_reference' => $this->stringValue($data['trxReference'] ?? null),
                'alternate_number' => $this->stringValue($data['alternateNumber'] ?? null),
                'status' => $isSuccess ? EarlyTerminationTransaction::STATUS_SUCCESS : EarlyTerminationTransaction::STATUS_FAILED,
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
    }

    private function findOrCreateTransaction(InsuranceReceivable $insuranceReceivable, ?User $user): EarlyTerminationTransaction
    {
        $existing = $insuranceReceivable->earlyTerminationTransactions()
            ->where('status', '!=', EarlyTerminationTransaction::STATUS_SUCCESS)
            ->latest('id')
            ->first();

        if ($existing instanceof EarlyTerminationTransaction) {
            return $existing;
        }

        for ($seconds = 0; $seconds < 10; $seconds++) {
            $reference = now()->copy()->addSeconds($seconds)->format('\P\A-\E\TYmdHis');

            try {
                return $insuranceReceivable->earlyTerminationTransactions()->create([
                    'trx_reference' => $reference,
                    'request_payload' => $this->payloadFor($insuranceReceivable, $reference),
                    'status' => EarlyTerminationTransaction::STATUS_PENDING,
                    'executed_by' => $user?->id,
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23000' && ! str_contains($exception->getMessage(), 'UNIQUE')) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages([
            'trx_reference' => 'Unable to generate unique early termination reference.',
        ]);
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
            'principalWaive' => $this->apiMoneyNumber($insuranceReceivable->loan_outstanding),
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
}
