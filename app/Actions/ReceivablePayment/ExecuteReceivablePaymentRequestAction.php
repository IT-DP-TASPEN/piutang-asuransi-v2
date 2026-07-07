<?php

namespace App\Actions\ReceivablePayment;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\PayloadBuilders\ReceivablePaymentGlPayloadBuilder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteReceivablePaymentRequestAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
        private readonly ReceivablePaymentGlPayloadBuilder $payloadBuilder,
        private readonly ValidateReceivablePaymentSavingAccountAction $savingAccountValidator,
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ReceivablePaymentRequest $request, User $user, ?string $notes = null): ReceivablePaymentRequest
    {
        $lock = Cache::lock("receivable-payment-request:{$request->id}:approve", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'payment_request' => 'Receivable payment request is already being processed.',
            ]);
        }

        try {
            $prepared = $this->prepareForExecution($request, $user);

            if ($prepared->status === ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED) {
                return $prepared;
            }

            if ($prepared->payment_source === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING) {
                $validation = $this->validateDebtorSavingBeforeGl($prepared, $user);

                if ($validation instanceof ReceivablePaymentRequest) {
                    return $validation;
                }
            }

            $transaction = $this->prepareGlTransaction($prepared, $user);

            if ($transaction->status === GlToGlTransaction::STATUS_SUCCESS) {
                return $this->recordPayment($prepared, $transaction, $user, $notes);
            }

            $result = $this->coreBankingClient->transferGlToGl($transaction->request_payload, $transaction, $user);
            $isSuccess = $result['response_code'] === '00';
            $description = $result['description'] ?: $result['error_message'] ?: 'GL-to-GL receivable payment failed.';

            if (! $isSuccess) {
                return $this->markGlFailed($prepared, $transaction, $result, $description, $user, $notes);
            }

            return $this->recordPayment($prepared, $transaction, $user, $notes, $result, $description);
        } finally {
            $lock->release();
        }
    }

    private function prepareForExecution(ReceivablePaymentRequest $request, User $user): ReceivablePaymentRequest
    {
        return DB::transaction(function () use ($request, $user): ReceivablePaymentRequest {
            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED) {
                return $locked;
            }

            if (! $locked->canRetry()) {
                throw ValidationException::withMessages([
                    'status' => 'Receivable payment request is not pending or retryable.',
                ]);
            }

            $approvalRequest = $this->pendingApprovalRequest($locked);
            $this->assertCanActOnApproval($approvalRequest, $user);
            $receivable = $locked->insuranceReceivable()
                ->withTrashed()
                ->lockForUpdate()
                ->firstOrFail();
            $this->validateReceivable($receivable);
            $this->validateRemaining($receivable, (string) $locked->amount);

            return $locked->refresh();
        });
    }

    private function validateDebtorSavingBeforeGl(ReceivablePaymentRequest $request, User $user): ?ReceivablePaymentRequest
    {
        try {
            $snapshot = $this->savingAccountValidator->handle(
                $request->insuranceReceivable,
                (string) $request->amount,
                $user,
                $request,
            );
        } catch (ValidationException $exception) {
            return $this->markValidationFailed($request, $this->validationMessage($exception));
        }

        DB::transaction(function () use ($request, $snapshot): void {
            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $receivable = $locked->insuranceReceivable()
                ->withTrashed()
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'saving_account_number' => trim((string) $receivable->saving_account_for_loan_repayment),
                'saving_account_snapshot' => $snapshot,
                'status' => ReceivablePaymentRequest::STATUS_SUBMITTED,
                'last_error_message' => null,
            ])->save();
        });

        return null;
    }

    private function prepareGlTransaction(ReceivablePaymentRequest $request, User $user): GlToGlTransaction
    {
        try {
            return DB::transaction(function () use ($request, $user): GlToGlTransaction {
                $locked = ReceivablePaymentRequest::query()
                    ->whereKey($request->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->gl_to_gl_transaction_id !== null) {
                    return GlToGlTransaction::query()
                        ->whereKey($locked->gl_to_gl_transaction_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $receivable = $locked->insuranceReceivable()
                    ->withTrashed()
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->validateReceivable($receivable);
                $this->validateRemaining($receivable, (string) $locked->amount);

                $transaction = GlToGlTransaction::query()->create([
                    'purpose' => GlToGlTransaction::PURPOSE_RECEIVABLE_PAYMENT,
                    'insurance_receivable_id' => $locked->insurance_receivable_id,
                    'receivable_payment_request_id' => $locked->id,
                    'status' => GlToGlTransaction::STATUS_PENDING,
                    'executed_by' => $user->id,
                ]);
                $referenceNumber = "IRPAY{$transaction->id}";
                $transaction->forceFill([
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $referenceNumber,
                    'request_payload' => $this->payloadBuilder->build($locked, $referenceNumber, $referenceNumber),
                ])->save();

                $locked->forceFill(['gl_to_gl_transaction_id' => $transaction->id])->save();

                return $transaction->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return GlToGlTransaction::query()
                ->where('receivable_payment_request_id', $request->id)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markGlFailed(
        ReceivablePaymentRequest $request,
        GlToGlTransaction $transaction,
        array $result,
        string $description,
        User $user,
        ?string $notes,
    ): ReceivablePaymentRequest {
        return DB::transaction(function () use ($request, $transaction, $result, $description, $user, $notes): ReceivablePaymentRequest {
            $transaction = GlToGlTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $transaction->forceFill($this->glResultAttributes($result, $description, $user, GlToGlTransaction::STATUS_FAILED))->save();

            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->forceFill([
                'status' => ReceivablePaymentRequest::STATUS_GL_FAILED,
                'approver_notes' => $notes,
                'last_error_message' => $description,
                'gl_executed_at' => $transaction->executed_at,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    /**
     * @param  array<string, mixed>|null  $result
     */
    private function recordPayment(
        ReceivablePaymentRequest $request,
        GlToGlTransaction $transaction,
        User $user,
        ?string $notes,
        ?array $result = null,
        ?string $description = null,
    ): ReceivablePaymentRequest {
        return DB::transaction(function () use ($request, $transaction, $user, $notes, $result, $description): ReceivablePaymentRequest {
            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED) {
                return $locked->refresh();
            }

            $receivable = $locked->insuranceReceivable()
                ->withTrashed()
                ->lockForUpdate()
                ->firstOrFail();
            $gl = GlToGlTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($result !== null) {
                $gl->forceFill($this->glResultAttributes(
                    $result,
                    $description ?: 'GL-to-GL receivable payment succeeded.',
                    $user,
                    GlToGlTransaction::STATUS_SUCCESS,
                ))->save();
            }

            if ($gl->status !== GlToGlTransaction::STATUS_SUCCESS) {
                throw ValidationException::withMessages([
                    'gl_to_gl_transaction' => 'Receivable payment GL-to-GL has not succeeded.',
                ]);
            }

            $this->validateReceivable($receivable);
            $remaining = $this->validateRemaining($receivable, (string) $locked->amount);
            $payment = ReceivablePayment::query()->create([
                'insurance_receivable_id' => $receivable->id,
                'receivable_payment_request_id' => $locked->id,
                'amount' => (string) BigDecimal::of($locked->amount)->toScale(2, RoundingMode::HalfUp),
                'paid_at' => $gl->executed_at ?? now(),
                'created_by' => $user->id,
            ]);

            $receivable->forceFill([
                'remaining_receivable_amount' => (string) $remaining->minus($locked->amount)->toScale(2, RoundingMode::HalfUp),
            ])->save();

            $gl->forceFill(['receivable_payment_id' => $payment->id])->save();

            $locked->forceFill([
                'status' => ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'gl_executed_at' => $gl->executed_at ?? now(),
                'receivable_payment_id' => $payment->id,
                'approver_notes' => $notes,
                'last_error_message' => null,
            ])->save();

            $this->approvalService->approveCurrentStep($this->pendingApprovalRequest($locked), $user, $notes);

            return $locked->refresh();
        });
    }

    private function markValidationFailed(ReceivablePaymentRequest $request, string $message): ReceivablePaymentRequest
    {
        return DB::transaction(function () use ($request, $message): ReceivablePaymentRequest {
            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->forceFill([
                'status' => ReceivablePaymentRequest::STATUS_VALIDATION_FAILED,
                'last_error_message' => $message,
            ])->save();

            return $locked->refresh();
        });
    }

    private function pendingApprovalRequest(ReceivablePaymentRequest $request): ApprovalRequest
    {
        $approvalRequest = $request->approvalRequest;

        if (! $approvalRequest instanceof ApprovalRequest || $approvalRequest->status !== ApprovalRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'approval' => 'Pending receivable payment approval request not found.',
            ]);
        }

        return $approvalRequest;
    }

    private function assertCanActOnApproval(ApprovalRequest $approvalRequest, User $user): void
    {
        $step = $approvalRequest->steps()
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

    private function validateReceivable(InsuranceReceivable $receivable): void
    {
        if ($receivable->trashed()) {
            throw ValidationException::withMessages([
                'receivable' => 'Payments cannot be recorded for deleted receivables.',
            ]);
        }

        if ($receivable->isLegacyOrigin()) {
            if (
                $receivable->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED
                && $receivable->system_status === InsuranceReceivable::SYSTEM_STATUS_LEGACY_IMPORTED
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'workflow_status' => 'Legacy receivable payments can only be recorded after import.',
            ]);
        }

        if (! in_array($receivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Insurance receivable payments can only be recorded after receivable formation.',
            ]);
        }
    }

    private function validateRemaining(InsuranceReceivable $receivable, string $amount): BigDecimal
    {
        $remaining = BigDecimal::of($receivable->remaining_receivable_amount)->toScale(2, RoundingMode::HalfUp);

        if (BigDecimal::of($amount)->isGreaterThan($remaining)) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount cannot exceed current remaining receivable amount.',
            ]);
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function glResultAttributes(array $result, string $description, User $user, string $status): array
    {
        return [
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
            'status' => $status,
            'executed_by' => $user->id,
            'executed_at' => now(),
        ];
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first()
            ?: 'Receivable payment validation failed.';
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || $exception->getCode() === '23505'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
