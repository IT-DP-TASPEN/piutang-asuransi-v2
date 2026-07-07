<?php

namespace App\Actions\ReceivablePayment;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitReceivablePaymentRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly ValidateReceivablePaymentSavingAccountAction $savingAccountValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(InsuranceReceivable $receivable, array $data, User $user): ReceivablePaymentRequest
    {
        if (! $user->can('Submit:ReceivablePaymentRequest')) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can submit receivable payment requests.',
            ]);
        }

        $amount = $this->validatedAmount($data['amount'] ?? null);
        $paymentSource = $this->validatedPaymentSource($data['payment_source'] ?? null);
        $snapshot = null;

        if ($paymentSource === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING) {
            $snapshot = $this->savingAccountValidator->handle($receivable, $amount, $user);
        }

        return DB::transaction(function () use ($receivable, $data, $user, $amount, $paymentSource, $snapshot): ReceivablePaymentRequest {
            $locked = $this->lockedReceivable($receivable);
            $this->validateReceivable($locked);
            $this->validateNoOpenRequest($locked);
            $this->validateRemaining($locked, $amount);

            /** @var ReceivablePaymentRequest $request */
            $request = ReceivablePaymentRequest::query()->create([
                'insurance_receivable_id' => $locked->id,
                'requested_by' => $user->id,
                'amount' => $amount,
                'payment_source' => $paymentSource,
                'saving_account_number' => $paymentSource === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING
                    ? trim((string) $locked->saving_account_for_loan_repayment)
                    : null,
                'saving_account_snapshot' => $snapshot,
                'status' => ReceivablePaymentRequest::STATUS_SUBMITTED,
                'maker_notes' => $data['maker_notes'] ?? null,
                'submitted_at' => now(),
            ]);

            $approvalRequest = $this->approvalService->submit(
                approvable: $request,
                workflowCode: ApprovalRequest::WORKFLOW_RECEIVABLE_PAYMENT,
                actor: $user,
                notes: $data['maker_notes'] ?? null,
                metadata: [
                    'insurance_receivable_id' => $locked->id,
                    'payment_source' => $paymentSource,
                    'amount' => $amount,
                ],
            );

            $request->forceFill(['approval_request_id' => $approvalRequest->id])->save();

            return $request->refresh();
        });
    }

    private function lockedReceivable(InsuranceReceivable $receivable): InsuranceReceivable
    {
        return $receivable->newQueryWithoutScopes()
            ->whereKey($receivable->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function validateReceivable(InsuranceReceivable $receivable): void
    {
        if ($receivable->trashed()) {
            throw ValidationException::withMessages([
                'receivable' => 'Payments cannot be requested for deleted receivables.',
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
                'workflow_status' => 'Legacy receivable payments can only be requested after import.',
            ]);
        }

        if (! in_array($receivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
        ], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Insurance receivable payments can only be requested after receivable formation.',
            ]);
        }
    }

    private function validateNoOpenRequest(InsuranceReceivable $receivable): void
    {
        if ($receivable->paymentRequests()->whereIn('status', [
            ReceivablePaymentRequest::STATUS_SUBMITTED,
            ReceivablePaymentRequest::STATUS_VALIDATION_FAILED,
            ReceivablePaymentRequest::STATUS_GL_FAILED,
        ])->exists()) {
            throw ValidationException::withMessages([
                'payment_request' => 'Open receivable payment request already exists.',
            ]);
        }
    }

    private function validateRemaining(InsuranceReceivable $receivable, string $amount): void
    {
        $remaining = BigDecimal::of($receivable->remaining_receivable_amount)->toScale(2, RoundingMode::HalfUp);

        if (BigDecimal::of($amount)->isGreaterThan($remaining)) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount cannot exceed current remaining receivable amount.',
            ]);
        }
    }

    private function validatedAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount is required.',
            ]);
        }

        try {
            $decimal = BigDecimal::of((string) $amount);
        } catch (MathException) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be numeric.',
            ]);
        }

        if (! $decimal->isGreaterThan('0')) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than 0.',
            ]);
        }

        return (string) $decimal->toScale(2, RoundingMode::HalfUp);
    }

    private function validatedPaymentSource(mixed $paymentSource): string
    {
        if (! is_string($paymentSource) || ! array_key_exists($paymentSource, ReceivablePaymentRequest::paymentSourceOptions())) {
            throw ValidationException::withMessages([
                'payment_source' => 'Payment source is required.',
            ]);
        }

        return $paymentSource;
    }
}
