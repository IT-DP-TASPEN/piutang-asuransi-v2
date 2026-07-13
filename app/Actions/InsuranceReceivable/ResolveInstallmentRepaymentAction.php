<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResolveInstallmentRepaymentAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly CoreBankingClient $coreBankingClient,
        private readonly PrepareAccountingValidationContractOutstandingAction $contractOutstandingAction,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:accounting-installment-repayment", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'installment_repayment' => 'Installment repayment is already being processed.',
            ]);
        }

        try {
            $context = $this->prepare($insuranceReceivable, $user);
            /** @var InsuranceReceivableInstallmentRepayment $repayment */
            $repayment = $context['repayment'];
            /** @var ApprovalRequest $request */
            $request = $context['request'];

            $result = $this->coreBankingClient->inquireLoan(
                accountNumber: $repayment->account_number,
                related: $repayment,
                requestedBy: $user,
            );

            if ($result['response_code'] !== '00') {
                $this->recordFailedResolve($repayment, $result, $this->resultMessage($result, 'Manual repayment verification failed.'), $user);

                throw ValidationException::withMessages([
                    'installment_repayment' => $this->resultMessage($result, 'Manual repayment verification failed.'),
                ]);
            }

            $afterOutstanding = $this->moneyDecimal($result['data']['loanOutStanding'] ?? null, 'Loan outstanding');
            $beforeOutstanding = BigDecimal::of((string) $repayment->loan_outstanding_before);

            if (! $afterOutstanding->isLessThan($beforeOutstanding)) {
                $message = 'Loan outstanding has not decreased after manual repayment.';
                $this->recordFailedResolve(
                    repayment: $repayment,
                    result: $result,
                    message: $message,
                    user: $user,
                    loanOutstandingAfter: (string) $afterOutstanding->toScale(2, RoundingMode::HalfUp),
                );

                throw ValidationException::withMessages([
                    'installment_repayment' => $message,
                ]);
            }

            DB::transaction(function () use ($insuranceReceivable, $repayment, $request, $result, $afterOutstanding, $user, $notes): void {
                $locked = InsuranceReceivableInstallmentRepayment::query()
                    ->whereKey($repayment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->canResolve()) {
                    throw ValidationException::withMessages([
                        'installment_repayment' => 'Installment repayment cannot be resolved in its current state.',
                    ]);
                }

                $receivable = InsuranceReceivable::query()
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->forceFillLoanSnapshot($receivable, $result['data']);
                $receivable->save();

                $locked->forceFill([
                    'post_repayment_inquiry_api_integration_log_id' => $result['log_id'],
                    'loan_outstanding_after' => (string) $afterOutstanding->toScale(2, RoundingMode::HalfUp),
                    'status' => InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'],
                    'response_payload' => $this->responsePayload($result),
                    'last_error_message' => null,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                ])->save();

                $this->stageLogger->log(
                    receivable: $receivable,
                    event: 'accounting_validation_installment_repayment_resolved',
                    fromStatus: null,
                    toStatus: InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY,
                    description: $notes ?: 'Manual installment repayment verified.',
                    metadata: ['installment_repayment_id' => $locked->id],
                    actor: $user,
                    approvalRequest: $request,
                    apiLog: $this->apiLog($result['log_id']),
                );

            });

            $businessDate = now('Asia/Jakarta')->toDateString();
            $this->contractOutstandingAction->handle($insuranceReceivable->refresh(), $user, $businessDate);
            $this->approvalService->approveCurrentStep($request->refresh(), $user, $notes);

            return $insuranceReceivable->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{repayment: InsuranceReceivableInstallmentRepayment, request: ApprovalRequest}
     */
    private function prepare(InsuranceReceivable $insuranceReceivable, User $user): array
    {
        return DB::transaction(function () use ($insuranceReceivable, $user): array {
            $locked = InsuranceReceivable::query()
                ->whereKey($insuranceReceivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $request = $this->activeAccountingRequest($locked);
            $this->assertCanActOnApproval($request, $user);

            $repayment = InsuranceReceivableInstallmentRepayment::query()
                ->where('insurance_receivable_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if (! $repayment instanceof InsuranceReceivableInstallmentRepayment || ! $repayment->canResolve()) {
                throw ValidationException::withMessages([
                    'installment_repayment' => 'Installment repayment cannot be resolved in its current state.',
                ]);
            }

            return [
                'repayment' => $repayment,
                'request' => $request,
            ];
        });
    }

    private function activeAccountingRequest(InsuranceReceivable $insuranceReceivable): ApprovalRequest
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

    private function assertCanActOnApproval(ApprovalRequest $request, User $user): void
    {
        $step = $request->steps()
            ->where('status', ApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->first();

        if (! $step instanceof ApprovalStep) {
            throw ValidationException::withMessages([
                'approval' => 'No pending approval step found.',
            ]);
        }

        if ($user->hasRole('super_admin')) {
            return;
        }

        if ($step->assigned_user_id !== null && $step->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'approval' => 'Approval step is assigned to another user.',
            ]);
        }

        if ($step->role_name !== null && ! $user->hasRole($step->role_name)) {
            throw ValidationException::withMessages([
                'approval' => "Approval step requires role {$step->role_name}.",
            ]);
        }
    }

    private function recordFailedResolve(
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
                    'response_code' => $result['response_code'],
                    'response_description' => $result['description'],
                    'response_payload' => $this->responsePayload($result),
                    'last_error_message' => $message,
                ])
                ->save();
        });

        $repayment->refresh();
        $this->stageLogger->log(
            receivable: $repayment->insuranceReceivable,
            event: 'accounting_validation_installment_repayment_resolve_failed',
            fromStatus: null,
            toStatus: $repayment->status,
            description: $message,
            metadata: ['installment_repayment_id' => $repayment->id],
            actor: $user,
            apiLog: $this->apiLog($result['log_id']),
        );
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
            'collectability' => $this->stringValue($data['collectability'] ?? null),
            'dpd' => $this->integerValue($data['dpd'] ?? null),
            'saving_account_for_loan_repayment' => $this->stringValue($data['saForLoanRepayment'] ?? null),
            'loan_outstanding' => (string) $this->moneyDecimal($data['loanOutStanding'] ?? null, 'Loan outstanding')->toScale(2, RoundingMode::HalfUp),
            'inquiry_completed_at' => now(),
        ]);
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

    private function resultMessage(array $result, string $fallback): string
    {
        return $result['description']
            ?: $result['error_message']
            ?: $fallback;
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
