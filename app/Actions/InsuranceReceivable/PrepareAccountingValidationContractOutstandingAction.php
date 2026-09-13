<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\ContractOutstanding\ContractOutstandingClient;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\ResolveLoanProductLsaTransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrepareAccountingValidationContractOutstandingAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly ContractOutstandingClient $contractOutstandingClient,
        private readonly ResolveLoanProductLsaTransactionType $lsaResolver,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, string $businessDate): InsuranceReceivable
    {
        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:contract-outstanding", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding validation is already being processed.',
            ]);
        }

        try {
            $loanInquiry = $this->freshLoanInquiry($insuranceReceivable, $user);
            $freshOutstanding = $this->moneyDecimal($loanInquiry['data']['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding');
            $receivable = $this->storeFreshLoanSnapshot($insuranceReceivable, $loanInquiry['data']);

            if ($receivable->contract_outstanding_amount !== null) {
                $this->validateExistingSnapshot($receivable, $freshOutstanding);
                $this->stageLogger->log(
                    receivable: $receivable,
                    event: 'contract_outstanding_snapshot_reused',
                    description: 'Existing Contract Outstanding snapshot reused and revalidated against fresh Fincloud outstanding.',
                    metadata: [
                        'contract_outstanding_amount' => $receivable->contract_outstanding_amount,
                        'fresh_fincloud_outstanding' => (string) $freshOutstanding->toScale(2, RoundingMode::HalfUp),
                    ],
                    actor: $user,
                    apiLog: $this->apiLog($loanInquiry['log_id']),
                );

                return $receivable->refresh();
            }

            $this->stageLogger->log(
                receivable: $receivable,
                event: 'contract_outstanding_inquiry_started',
                description: 'Contract Outstanding inquiry started.',
                metadata: ['requested_as_of' => $businessDate],
                actor: $user,
            );

            try {
                $contract = $this->contractOutstandingClient->inquire(
                    accountNumber: trim((string) $receivable->loan_account_number),
                    asOf: $businessDate,
                    related: $receivable,
                    requestedBy: $user,
                );
            } catch (Throwable $exception) {
                $this->stageLogger->log(
                    receivable: $receivable,
                    event: 'contract_outstanding_inquiry_failed',
                    description: $exception->getMessage(),
                    metadata: ['requested_as_of' => $businessDate],
                    actor: $user,
                );

                throw $exception;
            }

            $contractOutstanding = $contract->bakiDebet->toScale(2, RoundingMode::HalfUp);
            $this->validateContractAgainstFincloud($receivable, $contractOutstanding, $freshOutstanding);
            $spread = $freshOutstanding->minus($contractOutstanding)->toScale(2, RoundingMode::HalfUp);
            $resolved = $this->lsaResolver->resolve($contract->loanProduct);
            $productCode = $resolved['product_code'] ?? $this->lsaResolver->productCode($contract->loanProduct);
            $trxType = $resolved['trx_type'] ?? null;

            if ($spread->isGreaterThan('0') && $trxType === null) {
                $this->logInvalidContract($receivable, $user, 'Positive spread requires mapped LSA transaction type.', $contract->apiLogId);

                throw ValidationException::withMessages([
                    'contract_outstanding' => 'Positive spread requires mapped LSA transaction type.',
                ]);
            }

            $stored = DB::transaction(function () use ($receivable, $contract, $contractOutstanding, $productCode, $trxType): InsuranceReceivable {
                $locked = InsuranceReceivable::query()
                    ->whereKey($receivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->contract_outstanding_amount !== null) {
                    return $locked;
                }

                $locked->forceFill([
                    'contract_outstanding_amount' => (string) $contractOutstanding,
                    'contract_outstanding_requested_as_of' => $contract->requestedAsOf,
                    'contract_outstanding_as_of' => $contract->returnedAsOf,
                    'contract_outstanding_product_code' => $productCode,
                    'contract_outstanding_trx_type' => $trxType,
                    'contract_outstanding_api_log_id' => $contract->apiLogId,
                ])->save();

                return $locked->refresh();
            });

            if ($contract->returnedAsOf !== null && $contract->returnedAsOf !== $contract->requestedAsOf) {
                $this->stageLogger->log(
                    receivable: $stored,
                    event: 'contract_outstanding_as_of_mismatch',
                    description: 'Contract Outstanding returned AsOf differs from requested AsOf.',
                    metadata: [
                        'requested_as_of' => $contract->requestedAsOf,
                        'returned_as_of' => $contract->returnedAsOf,
                    ],
                    actor: $user,
                    apiLog: $this->apiLog($contract->apiLogId),
                );
            }

            $this->stageLogger->log(
                receivable: $stored,
                event: 'contract_outstanding_snapshot_stored',
                description: 'Contract Outstanding snapshot stored.',
                metadata: [
                    'contract_outstanding_amount' => (string) $contractOutstanding,
                    'fresh_fincloud_outstanding' => (string) $freshOutstanding->toScale(2, RoundingMode::HalfUp),
                    'spread' => (string) $spread,
                    'product_code' => $productCode,
                    'trx_type' => $trxType,
                ],
                actor: $user,
                apiLog: $this->apiLog($contract->apiLogId),
            );

            return $stored;
        } finally {
            $lock->release();
        }
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

    private function storeFreshLoanSnapshot(InsuranceReceivable $insuranceReceivable, array $data): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $data): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $branchCode = $this->stringValue($data['branchCode'] ?? null);

            if ($branchCode !== null && $branchCode !== trim((string) $locked->branch_code)) {
                throw ValidationException::withMessages([
                    'loan_account_number' => "Loan branch {$branchCode} does not match receivable branch {$locked->branch_code}.",
                ]);
            }

            $locked->forceFill([
                'loan_account_number' => $this->stringValue($data['accountNumber'] ?? null) ?? $locked->loan_account_number,
                'alt_number' => $this->stringValue($data['altNumber'] ?? null) ?? $locked->alt_number,
                'collectability' => $this->stringValue($data['collectability'] ?? null),
                'dpd' => $this->integerValue($data['dpd'] ?? null),
                'saving_account_for_loan_repayment' => $this->stringValue($data['saForLoanRepayment'] ?? null),
                'loan_outstanding' => (string) $this->moneyDecimal($data['loanOutStanding'] ?? null, 'Fresh Fincloud outstanding')->toScale(2, RoundingMode::HalfUp),
                'inquiry_completed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function validateExistingSnapshot(InsuranceReceivable $receivable, BigDecimal $freshOutstanding): void
    {
        if ($receivable->contract_outstanding_requested_as_of === null) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Existing Contract Outstanding snapshot is incomplete and requires review.',
            ]);
        }

        $contract = $this->moneyDecimal($receivable->contract_outstanding_amount, 'Contract outstanding snapshot');
        $this->validateContractAgainstFincloud($receivable, $contract, $freshOutstanding);
    }

    private function validateContractAgainstFincloud(InsuranceReceivable $receivable, BigDecimal $contract, BigDecimal $freshOutstanding): void
    {
        if ($contract->isLessThanOrEqualTo('0')) {
            $this->logInvalidContract($receivable, null, 'Contract Outstanding must be greater than zero.', $receivable->contract_outstanding_api_log_id);

            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding must be greater than zero.',
            ]);
        }

        if ($contract->isGreaterThan($freshOutstanding)) {
            $this->logInvalidContract($receivable, null, 'Contract Outstanding cannot exceed fresh Fincloud outstanding.', $receivable->contract_outstanding_api_log_id);

            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding cannot exceed fresh Fincloud outstanding.',
            ]);
        }
    }

    private function logInvalidContract(InsuranceReceivable $receivable, ?User $user, string $message, mixed $apiLogId): void
    {
        $this->stageLogger->log(
            receivable: $receivable,
            event: 'contract_outstanding_invalid_block',
            description: $message,
            actor: $user,
            apiLog: $this->apiLog($apiLogId),
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

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function apiLog(mixed $id): ?ApiIntegrationLog
    {
        return $id === null ? null : ApiIntegrationLog::query()->find($id);
    }
}
