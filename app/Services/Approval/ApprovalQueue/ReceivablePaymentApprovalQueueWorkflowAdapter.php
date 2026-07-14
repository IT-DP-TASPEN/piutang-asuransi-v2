<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Actions\ReceivablePayment\ExecuteReceivablePaymentRequestAction;
use App\Actions\ReceivablePayment\RejectReceivablePaymentRequestAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\ApprovalRequest;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;

class ReceivablePaymentApprovalQueueWorkflowAdapter extends BaseApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string
    {
        return 'Receivable Payment';
    }

    public function summary(ApprovalRequest $request): string
    {
        $payment = $this->paymentRequest($request)?->loadMissing('insuranceReceivable');

        return $payment
            ? collect([
                $payment->insuranceReceivable?->customer_name,
                ReceivablePaymentRequest::paymentSourceOptions()[$payment->payment_source] ?? $payment->payment_source,
            ])->filter()->join(' - ')
            : 'Receivable payment';
    }

    public function reference(ApprovalRequest $request): ?string
    {
        return $this->paymentRequest($request) ? 'Payment Request #'.$request->approvable_id : null;
    }

    public function branchLabel(ApprovalRequest $request): ?string
    {
        $payment = $this->paymentRequest($request)?->loadMissing('insuranceReceivable.branchOffice');

        return $payment?->insuranceReceivable?->branchOffice?->branch_name
            ?? $payment?->insuranceReceivable?->branch_code;
    }

    public function amountLabel(ApprovalRequest $request): ?string
    {
        return $this->money($this->paymentRequest($request)?->amount);
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        $receivable = $this->paymentRequest($request)?->insuranceReceivable;

        return $receivable ? InsuranceReceivableResource::getUrl('view', ['record' => $receivable]) : null;
    }

    public function canApprove(ApprovalRequest $request, User $user): bool
    {
        $payment = $this->paymentRequest($request);

        return $payment instanceof ReceivablePaymentRequest
            && $payment->canRetry()
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can('approve', $payment);
    }

    public function canReject(ApprovalRequest $request, User $user): bool
    {
        $payment = $this->paymentRequest($request);

        return $payment instanceof ReceivablePaymentRequest
            && $payment->status === ReceivablePaymentRequest::STATUS_SUBMITTED
            && $this->submittedAndCurrent($request)
            && $this->canActOnCurrentStep($request, $user)
            && $user->can('reject', $payment);
    }

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(ExecuteReceivablePaymentRequestAction::class)->handle($this->paymentRequestOrFail($request), $user, $notes);
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        app(RejectReceivablePaymentRequestAction::class)->handle($this->paymentRequestOrFail($request), $user, $notes);
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return $action === 'reject';
    }

    public function confirmationDescription(string $action, ApprovalRequest $request): ?string
    {
        return $action === 'approve'
            ? 'Approval executes the existing receivable payment flow and may call Core Banking GL-to-GL.'
            : null;
    }

    public function snapshot(ApprovalRequest $request): array
    {
        $payment = $this->paymentRequest($request)?->loadMissing('insuranceReceivable');

        if (! $payment) {
            return [];
        }

        return [
            'Customer' => $this->value($payment->insuranceReceivable?->customer_name),
            'Loan account' => $this->value($payment->insuranceReceivable?->loan_account_number),
            'Branch' => $this->branchLabel($request),
            'Payment source' => ReceivablePaymentRequest::paymentSourceOptions()[$payment->payment_source] ?? $payment->payment_source,
            'Amount' => $this->money($payment->amount),
            'Remaining before' => $this->money($payment->insuranceReceivable?->remaining_receivable_amount),
            'Saving account' => $this->value($payment->saving_account_number),
            'Request status' => ReceivablePaymentRequest::statusOptions()[$payment->status] ?? $payment->status,
            'Last error' => $this->value($payment->last_error_message),
        ];
    }

    private function paymentRequest(ApprovalRequest $request): ?ReceivablePaymentRequest
    {
        return $this->approvable($request, ReceivablePaymentRequest::class);
    }

    private function paymentRequestOrFail(ApprovalRequest $request): ReceivablePaymentRequest
    {
        return $this->paymentRequest($request) ?? throw new \RuntimeException('Receivable payment request not found.');
    }
}
