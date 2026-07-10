<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessAccountingValidationInstallmentRepaymentAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, bool $retry = false): InsuranceReceivable
    {
        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:accounting-installment-repayment", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'installment_repayment' => 'Installment repayment is already being processed.',
            ]);
        }

        try {
            $this->assertProcessable($insuranceReceivable, $retry);

            if ($this->hasCompletedRepayment($insuranceReceivable)) {
                return $insuranceReceivable->refresh();
            }

            $inquiry = $this->coreBankingClient->inquireLoan(
                accountNumber: trim((string) $insuranceReceivable->loan_account_number),
                related: $insuranceReceivable,
                requestedBy: $user,
            );

            if ($inquiry['response_code'] !== '00') {
                throw ValidationException::withMessages([
                    'loan_account_number' => $inquiry['description']
                        ?: $inquiry['error_message']
                        ?: 'Loan inquiry failed.',
                ]);
            }

            $receivable = $this->updateReceivableLoanSnapshot($insuranceReceivable, $inquiry['data']);
            $nextDueDate = $this->dateValue($inquiry['data']['nextDueDate'] ?? null);

            if (! $this->repaymentRequired($receivable, $nextDueDate)) {
                return $receivable->refresh();
            }

            $repayment = $this->createOrUpdateRequiredRepayment(
                receivable: $receivable,
                user: $user,
                inquiry: $inquiry,
                nextDueDate: $nextDueDate,
            );

            if (in_array($repayment->status, [
                InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
            ], true)) {
                return $receivable->refresh();
            }

            $this->validateBalance($repayment, $user);
            $reference = 'IRREP'.now()->format('YmdHisv');
            $payload = $this->repaymentPayload($repayment, $reference);

            $repayment = DB::transaction(function () use ($repayment, $payload, $reference, $user): InsuranceReceivableInstallmentRepayment {
                $locked = InsuranceReceivableInstallmentRepayment::query()
                    ->whereKey($repayment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($locked->status, [
                    InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                    InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
                ], true)) {
                    return $locked;
                }

                $locked->forceFill([
                    'status' => InsuranceReceivableInstallmentRepayment::STATUS_PROCESSING,
                    'reference_number' => $reference,
                    'request_payload' => $payload,
                    'response_payload' => null,
                    'response_code' => null,
                    'response_description' => null,
                    'last_error_message' => null,
                    'executed_by' => $user->id,
                ])->save();

                return $locked->refresh();
            });

            if (in_array($repayment->status, [
                InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
            ], true)) {
                return $receivable->refresh();
            }

            $result = $this->coreBankingClient->repayLoan($payload, $repayment, $user);

            if (! $result['ok']) {
                $this->markRepaymentPostFailed($repayment, $result, $user);

                throw ValidationException::withMessages([
                    'installment_repayment' => $this->resultMessage($result, 'Installment repayment failed.'),
                ]);
            }

            $this->markRepaymentPostSucceeded($repayment, $result, $user);

            $postInquiry = $this->coreBankingClient->inquireLoan(
                accountNumber: $repayment->account_number,
                related: $repayment,
                requestedBy: $user,
            );

            if ($postInquiry['response_code'] !== '00') {
                $this->markVerificationFailedAfterExecution(
                    repayment: $repayment,
                    result: $postInquiry,
                    message: $this->resultMessage($postInquiry, 'Post-repayment loan inquiry failed.'),
                    user: $user,
                );

                throw ValidationException::withMessages([
                    'installment_repayment' => 'Installment repayment was posted, but post-repayment verification failed. Use Resolve Repayment.',
                ]);
            }

            $afterOutstanding = $this->moneyDecimal($postInquiry['data']['loanOutStanding'] ?? null, 'Post-repayment loan outstanding');
            $beforeOutstanding = BigDecimal::of((string) $repayment->loan_outstanding_before);

            if (! $afterOutstanding->isLessThan($beforeOutstanding)) {
                $this->markVerificationFailedAfterExecution(
                    repayment: $repayment,
                    result: $postInquiry,
                    message: 'Loan outstanding has not decreased after installment repayment.',
                    user: $user,
                    loanOutstandingAfter: (string) $afterOutstanding->toScale(2, RoundingMode::HalfUp),
                );

                throw ValidationException::withMessages([
                    'installment_repayment' => 'Loan outstanding has not decreased after installment repayment. Use Resolve Repayment.',
                ]);
            }

            return $this->markExecuted($repayment, $postInquiry, $afterOutstanding, $user);
        } finally {
            $lock->release();
        }
    }

    private function hasCompletedRepayment(InsuranceReceivable $insuranceReceivable): bool
    {
        $repayment = $insuranceReceivable->installmentRepayment()->first();

        return $repayment instanceof InsuranceReceivableInstallmentRepayment
            && in_array($repayment->status, [
                InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
            ], true);
    }

    private function assertProcessable(InsuranceReceivable $insuranceReceivable, bool $retry): void
    {
        $repayment = DB::transaction(function () use ($insuranceReceivable): ?InsuranceReceivableInstallmentRepayment {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isLegacyOrigin() || $locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Insurance receivable cannot enter Accounting Validation repayment.',
                ]);
            }

            if ($locked->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Installment repayment can only run during Accounting Validation.',
                ]);
            }

            $this->activeAccountingApprovalRequest($locked);

            return InsuranceReceivableInstallmentRepayment::query()
                ->where('insurance_receivable_id', $locked->id)
                ->lockForUpdate()
                ->first();
        });

        if (! $repayment instanceof InsuranceReceivableInstallmentRepayment) {
            return;
        }

        if (in_array($repayment->status, [
            InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
            InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
        ], true)) {
            return;
        }

        if ($repayment->status === InsuranceReceivableInstallmentRepayment::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                'installment_repayment' => 'Installment repayment is already processing.',
            ]);
        }

        if ($retry && ! $repayment->canRetry()) {
            throw ValidationException::withMessages([
                'installment_repayment' => 'This repayment state cannot be retried automatically. Use Resolve Repayment.',
            ]);
        }

        if (! $retry && in_array($repayment->status, [
            InsuranceReceivableInstallmentRepayment::STATUS_VALIDATION_FAILED,
            InsuranceReceivableInstallmentRepayment::STATUS_FAILED,
            InsuranceReceivableInstallmentRepayment::STATUS_UNKNOWN_TIMEOUT,
            InsuranceReceivableInstallmentRepayment::STATUS_VERIFICATION_FAILED_AFTER_EXECUTION,
        ], true)) {
            throw ValidationException::withMessages([
                'installment_repayment' => 'Installment repayment is blocked. Use Retry Repayment or Resolve Repayment.',
            ]);
        }
    }

    private function createOrUpdateRequiredRepayment(
        InsuranceReceivable $receivable,
        User $user,
        array $inquiry,
        ?string $nextDueDate,
    ): InsuranceReceivableInstallmentRepayment {
        $data = $inquiry['data'];
        $installment = $this->moneyDecimal($data['installmentAmount'] ?? null, 'Installment amount');
        $beforeOutstanding = $this->moneyDecimal($data['loanOutStanding'] ?? null, 'Loan outstanding');
        $accountNumber = trim((string) ($this->stringValue($data['accountNumber'] ?? null) ?? $receivable->loan_account_number));
        $branchCode = trim((string) ($this->stringValue($data['branchCode'] ?? null) ?? $receivable->branch_code));

        if ($accountNumber === '' || $branchCode === '') {
            throw ValidationException::withMessages([
                'loan_account_number' => 'Loan inquiry response must include account number and branch code.',
            ]);
        }

        $repayment = DB::transaction(function () use ($receivable, $inquiry, $data, $nextDueDate, $installment, $beforeOutstanding, $accountNumber, $branchCode): InsuranceReceivableInstallmentRepayment {
            $locked = InsuranceReceivableInstallmentRepayment::query()
                ->where('insurance_receivable_id', $receivable->id)
                ->lockForUpdate()
                ->first();

            if (
                $locked instanceof InsuranceReceivableInstallmentRepayment
                && in_array($locked->status, [
                    InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                    InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
                ], true)
            ) {
                return $locked;
            }

            $attributes = [
                'api_integration_log_id' => $inquiry['log_id'],
                'account_number' => $accountNumber,
                'alt_number' => $this->stringValue($data['altNumber'] ?? null),
                'branch_code' => $branchCode,
                'saving_account_number' => $this->stringValue($data['saForLoanRepayment'] ?? null),
                'installment_amount' => (string) $installment->toScale(2, RoundingMode::HalfUp),
                'loan_outstanding_before' => (string) $beforeOutstanding->toScale(2, RoundingMode::HalfUp),
                'loan_outstanding_after' => null,
                'next_due_date' => $nextDueDate,
                'date_of_death' => $receivable->date_of_death?->toDateString(),
                'status' => InsuranceReceivableInstallmentRepayment::STATUS_REQUIRED,
                'last_error_message' => null,
                'resolved_by' => null,
                'resolved_at' => null,
            ];

            if ($locked instanceof InsuranceReceivableInstallmentRepayment) {
                $locked->forceFill($attributes)->save();

                return $locked->refresh();
            }

            return InsuranceReceivableInstallmentRepayment::query()->create([
                'insurance_receivable_id' => $receivable->id,
                ...$attributes,
            ]);
        });

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'accounting_validation_installment_repayment_required',
            fromStatus: $repayment->getOriginal('status'),
            toStatus: $repayment->status,
            description: 'Installment repayment is required before Accounting Validation approval.',
            metadata: ['installment_repayment_id' => $repayment->id],
            actor: $user,
            apiLog: $this->apiLog($inquiry['log_id']),
        );

        return $repayment;
    }

    private function validateBalance(InsuranceReceivableInstallmentRepayment $repayment, User $user): array
    {
        $account = trim((string) $repayment->saving_account_number);

        if ($account === '') {
            $this->markValidationFailed($repayment, 'Saving account for loan repayment is empty.', null, $user);
            throw ValidationException::withMessages([
                'saving_account_number' => 'Saving account for loan repayment is empty.',
            ]);
        }

        $result = $this->coreBankingClient->inquireBalance($account, $repayment, $user);

        if (! $result['ok']) {
            $message = $this->resultMessage($result, 'Saving account balance inquiry failed.');
            $this->markValidationFailed($repayment, $message, $result, $user);

            throw ValidationException::withMessages([
                'saving_account_number' => $message,
            ]);
        }

        $data = $result['data'];
        $status = trim((string) ($data['documentStatus'] ?? ''));

        if ($status !== 'Active') {
            $message = 'Saving account document status must be Active.';
            $this->markValidationFailed($repayment, $message, $result, $user);

            throw ValidationException::withMessages(['saving_account_number' => $message]);
        }

        if ($this->isDormant($data)) {
            $message = 'Dormant saving account cannot be used.';
            $this->markValidationFailed($repayment, $message, $result, $user);

            throw ValidationException::withMessages(['saving_account_number' => $message]);
        }

        $available = $this->moneyDecimal($data['availableBalance'] ?? null, 'Available balance');

        if ($available->isLessThan(BigDecimal::of((string) $repayment->installment_amount))) {
            $message = 'Available balance is less than installment amount.';
            $this->markValidationFailed($repayment, $message, $result, $user);

            throw ValidationException::withMessages(['saving_account_number' => $message]);
        }

        DB::transaction(function () use ($repayment, $result): void {
            InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'balance_api_integration_log_id' => $result['log_id'],
                    'last_error_message' => null,
                ])
                ->save();
        });

        return $data;
    }

    private function markValidationFailed(
        InsuranceReceivableInstallmentRepayment $repayment,
        string $message,
        ?array $result,
        User $user,
    ): void {
        DB::transaction(function () use ($repayment, $message, $result): void {
            InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'balance_api_integration_log_id' => $result['log_id'] ?? null,
                    'status' => InsuranceReceivableInstallmentRepayment::STATUS_VALIDATION_FAILED,
                    'response_code' => $result['response_code'] ?? null,
                    'response_description' => $result['description'] ?? null,
                    'response_payload' => $result === null ? null : $this->responsePayload($result),
                    'last_error_message' => $message,
                ])
                ->save();
        });

        $repayment->refresh();
        $this->logRepaymentEvent($repayment, 'accounting_validation_installment_repayment_validation_failed', $message, $user, $result);
    }

    private function markRepaymentPostFailed(InsuranceReceivableInstallmentRepayment $repayment, array $result, User $user): void
    {
        $status = $this->isUnknownResult($result)
            ? InsuranceReceivableInstallmentRepayment::STATUS_UNKNOWN_TIMEOUT
            : InsuranceReceivableInstallmentRepayment::STATUS_FAILED;
        $message = $this->resultMessage($result, 'Installment repayment failed.');

        DB::transaction(function () use ($repayment, $result, $status, $message): void {
            InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'api_integration_log_id' => $result['log_id'],
                    'status' => $status,
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'],
                    'response_payload' => $this->responsePayload($result),
                    'last_error_message' => $message,
                    'executed_at' => null,
                ])
                ->save();
        });

        $repayment->refresh();
        $this->logRepaymentEvent($repayment, 'accounting_validation_installment_repayment_post_failed', $message, $user, $result);
    }

    private function markRepaymentPostSucceeded(InsuranceReceivableInstallmentRepayment $repayment, array $result, User $user): void
    {
        DB::transaction(function () use ($repayment, $result): void {
            InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'api_integration_log_id' => $result['log_id'],
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'],
                    'response_payload' => $this->responsePayload($result),
                    'executed_at' => now(),
                    'last_error_message' => null,
                ])
                ->save();
        });

        $repayment->refresh();
        $this->logRepaymentEvent($repayment, 'accounting_validation_installment_repayment_post_succeeded', 'Installment repayment API succeeded.', $user, $result);
    }

    private function markVerificationFailedAfterExecution(
        InsuranceReceivableInstallmentRepayment $repayment,
        array $result,
        string $message,
        User $user,
        ?string $loanOutstandingAfter = null,
    ): void {
        DB::transaction(function () use ($repayment, $result, $message, $loanOutstandingAfter): void {
            InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'post_repayment_inquiry_api_integration_log_id' => $result['log_id'],
                    'loan_outstanding_after' => $loanOutstandingAfter,
                    'status' => InsuranceReceivableInstallmentRepayment::STATUS_VERIFICATION_FAILED_AFTER_EXECUTION,
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'],
                    'response_payload' => $this->responsePayload($result),
                    'last_error_message' => $message,
                ])
                ->save();
        });

        $repayment->refresh();
        $this->logRepaymentEvent($repayment, 'accounting_validation_installment_repayment_verification_failed', $message, $user, $result);
    }

    private function markExecuted(
        InsuranceReceivableInstallmentRepayment $repayment,
        array $postInquiry,
        BigDecimal $afterOutstanding,
        User $user,
    ): InsuranceReceivable {
        return DB::transaction(function () use ($repayment, $postInquiry, $afterOutstanding, $user): InsuranceReceivable {
            $locked = InsuranceReceivableInstallmentRepayment::query()
                ->whereKey($repayment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $receivable = $locked->insuranceReceivable()->lockForUpdate()->firstOrFail();
            $this->forceFillLoanSnapshot($receivable, $postInquiry['data']);
            $receivable->save();

            $locked->forceFill([
                'post_repayment_inquiry_api_integration_log_id' => $postInquiry['log_id'],
                'loan_outstanding_after' => (string) $afterOutstanding->toScale(2, RoundingMode::HalfUp),
                'status' => InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                'response_code' => $postInquiry['response_code'],
                'response_description' => $postInquiry['description'],
                'response_payload' => $this->responsePayload($postInquiry),
                'last_error_message' => null,
            ])->save();

            $this->stageLogger->log(
                receivable: $receivable,
                event: 'accounting_validation_installment_repayment_executed',
                fromStatus: InsuranceReceivableInstallmentRepayment::STATUS_PROCESSING,
                toStatus: InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
                description: 'Installment repayment executed and verified.',
                metadata: ['installment_repayment_id' => $locked->id],
                actor: $user,
                apiLog: $this->apiLog($postInquiry['log_id']),
            );

            return $receivable->refresh();
        });
    }

    private function updateReceivableLoanSnapshot(InsuranceReceivable $insuranceReceivable, array $data): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $data): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->forceFillLoanSnapshot($locked, $data);
            $locked->save();

            return $locked->refresh();
        });
    }

    private function forceFillLoanSnapshot(InsuranceReceivable $receivable, array $data): void
    {
        $branchCode = $this->stringValue($data['branchCode'] ?? null);
        $branchOfficeId = $branchCode === null
            ? $receivable->branch_office_id
            : BranchOffice::query()->where('branch_code', $branchCode)->value('id');

        $receivable->forceFill([
            'branch_office_id' => $branchOfficeId ?? $receivable->branch_office_id,
            'branch_code' => $branchCode ?? $receivable->branch_code,
            'loan_account_number' => $this->stringValue($data['accountNumber'] ?? null) ?? $receivable->loan_account_number,
            'alt_number' => $this->stringValue($data['altNumber'] ?? null),
            'cif_no' => $this->stringValue($data['cifNo'] ?? null) ?? $receivable->cif_no,
            'cif_no_alt' => $this->stringValue($data['cifNoAlt'] ?? null) ?? $receivable->cif_no_alt,
            'customer_name' => $this->stringValue($data['customerName'] ?? null) ?? $receivable->customer_name,
            'collectability' => $this->stringValue($data['collectability'] ?? null),
            'dpd' => $this->integerValue($data['dpd'] ?? null),
            'product_id' => $this->stringValue($data['productID'] ?? null) ?? $receivable->product_id,
            'product_name' => $this->stringValue($data['productName'] ?? null) ?? $receivable->product_name,
            'saving_account_for_loan_repayment' => $this->stringValue($data['saForLoanRepayment'] ?? null),
            'start_period' => $this->dateValue($data['startPeriod'] ?? null) ?? $receivable->start_period,
            'end_period' => $this->dateValue($data['endPeriod'] ?? null) ?? $receivable->end_period,
            'credit_limit' => $this->moneyStringOrNull($data['creditLimit'] ?? null) ?? $receivable->credit_limit,
            'loan_outstanding' => $this->moneyStringOrNull($data['loanOutStanding'] ?? null) ?? $receivable->loan_outstanding,
            'inquiry_completed_at' => now(),
        ]);
    }

    private function activeAccountingApprovalRequest(InsuranceReceivable $insuranceReceivable): ApprovalRequest
    {
        $request = $insuranceReceivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active Accounting Validation approval request not found.',
            ]);
        }

        return $request;
    }

    private function repaymentRequired(InsuranceReceivable $receivable, ?string $nextDueDate): bool
    {
        if ($receivable->date_of_death === null || $nextDueDate === null) {
            return false;
        }

        return $receivable->date_of_death->toDateString() < $nextDueDate;
    }

    private function repaymentPayload(InsuranceReceivableInstallmentRepayment $repayment, string $reference): array
    {
        return [
            'trxReference' => $reference,
            'accountNumber' => $repayment->account_number,
            'altNumber' => $repayment->alt_number ?? '',
            'paymentAmount' => (string) BigDecimal::of((string) $repayment->installment_amount)->toScale(2, RoundingMode::HalfUp),
            'description' => "IR #{$repayment->insurance_receivable_id} Repayment",
            'branchCode' => $repayment->branch_code,
        ];
    }

    private function isDormant(array $data): bool
    {
        foreach (['accountStatus', 'status', 'documentStatus'] as $key) {
            $value = strtolower((string) ($data[$key] ?? ''));

            if (str_contains($value, 'dormant')) {
                return true;
            }
        }

        return false;
    }

    private function isUnknownResult(array $result): bool
    {
        return $result['error_message'] !== null
            || ($result['status'] === null && $result['response_code'] === null);
    }

    private function resultMessage(array $result, string $fallback): string
    {
        return $result['description']
            ?: $result['error_message']
            ?: $fallback;
    }

    private function responsePayload(array $result): array
    {
        return [
            'status' => $result['status'],
            'response_code' => $result['response_code'],
            'description' => $result['description'],
            'data' => $result['data'],
            'raw_body' => $result['raw_body'],
            'log_id' => $result['log_id'],
            'error_message' => $result['error_message'],
        ];
    }

    private function logRepaymentEvent(
        InsuranceReceivableInstallmentRepayment $repayment,
        string $event,
        string $message,
        User $user,
        ?array $result = null,
    ): void {
        $receivable = $repayment->insuranceReceivable;

        $this->stageLogger->log(
            receivable: $receivable,
            event: $event,
            fromStatus: null,
            toStatus: $repayment->status,
            description: $message,
            metadata: [
                'installment_repayment_id' => $repayment->id,
                'reference_number' => $repayment->reference_number,
            ],
            actor: $user,
            apiLog: $this->apiLog($result['log_id'] ?? null),
        );
    }

    private function apiLog(mixed $id): ?ApiIntegrationLog
    {
        return $id === null ? null : ApiIntegrationLog::query()->find($id);
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

    private function moneyStringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $this->moneyDecimal($value, 'Amount')->toScale(2, RoundingMode::HalfUp);
    }

    private function moneyDecimal(mixed $value, string $label): BigDecimal
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'amount' => "{$label} is required.",
            ]);
        }

        if (is_float($value)) {
            throw ValidationException::withMessages([
                'amount' => "{$label} must be a decimal string.",
            ]);
        }

        try {
            return BigDecimal::of(str_replace(',', '', trim((string) $value)))->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'amount' => "{$label} must be numeric.",
            ]);
        }
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $stringValue = trim((string) $value);

            if (preg_match('/^\d{8}$/', $stringValue) === 1) {
                return CarbonImmutable::createFromFormat('Ymd', $stringValue)->toDateString();
            }

            return CarbonImmutable::parse($stringValue)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
