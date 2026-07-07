<?php

namespace App\Actions\ReceivablePayment;

use App\Models\ApprovalRequest;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectReceivablePaymentRequestAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(ReceivablePaymentRequest $request, User $user, ?string $notes = null): ReceivablePaymentRequest
    {
        if (! $user->can('reject', $request)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can reject receivable payment requests.',
            ]);
        }

        return DB::transaction(function () use ($request, $user, $notes): ReceivablePaymentRequest {
            $locked = ReceivablePaymentRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $approvalRequest = $locked->approvalRequest;

            if (! $approvalRequest instanceof ApprovalRequest || $approvalRequest->status !== ApprovalRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'approval' => 'Pending receivable payment approval request not found.',
                ]);
            }

            $this->approvalService->rejectCurrentStep($approvalRequest, $user, $notes);

            $locked->forceFill([
                'status' => ReceivablePaymentRequest::STATUS_REJECTED,
                'approver_notes' => $notes,
            ])->save();

            return $locked->refresh();
        });
    }
}
