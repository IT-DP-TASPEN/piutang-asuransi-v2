<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmCollectabilityChangeCompletedAction
{
    public function __construct(
        private readonly PerformLoanInquiryAction $loanInquiryAction,
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        $this->assertConfirmable($insuranceReceivable, $user);

        $this->stageLogger->log(
            receivable: $insuranceReceivable,
            event: 'it_collectability_confirmation_attempted',
            description: 'IT requested fresh loan validation before collectability confirmation.',
            actor: $user,
        );

        $receivable = $this->loanInquiryAction->handle($insuranceReceivable, $user);
        $apiLog = $this->latestApiLog($receivable);

        try {
            $expectedOper = $this->operAccount->expected($receivable);
        } catch (ValidationException $exception) {
            return $this->returnToBranchMaker($receivable, $user, $this->validationMessage($exception), null, $apiLog);
        }

        $actualOper = $this->operAccount->actual($receivable);

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'it_fresh_loan_validation_completed',
            description: 'Fresh loan data received for IT collectability confirmation.',
            metadata: [
                'fresh_collectability' => $receivable->collectability,
                'expected_oper_account' => $expectedOper,
                'actual_repayment_account' => $actualOper,
            ],
            actor: $user,
            apiLog: $apiLog,
        );

        if ($actualOper !== $expectedOper) {
            $message = "Fresh repayment account must be branch OPER {$expectedOper}; actual: ".($actualOper === '' ? '(empty)' : $actualOper).'.';

            return $this->returnToBranchMaker($receivable, $user, $message, $expectedOper, $apiLog);
        }

        $collectability = trim((string) $receivable->collectability);

        if ($collectability !== '5') {
            $message = 'Fresh collectability must be 5; actual: '.($collectability === '' ? '(empty)' : $collectability).'.';

            $receivable->forceFill(['last_error_message' => $message])->save();
            $this->stageLogger->log(
                receivable: $receivable,
                event: 'it_collectability_confirmation_blocked',
                fromStatus: $receivable->workflow_status,
                toStatus: $receivable->workflow_status,
                description: $message,
                metadata: ['fresh_collectability' => $collectability],
                actor: $user,
                apiLog: $apiLog,
            );

            throw ValidationException::withMessages(['collectability' => $message]);
        }

        return DB::transaction(function () use ($receivable, $user, $expectedOper, $apiLog): InsuranceReceivable {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertConfirmable($locked, $user);

            $approvalRequest = $this->approvalService->submit(
                approvable: $locked,
                workflowCode: ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
                actor: $user,
                notes: 'Fresh OPER and collectability 5 validated by IT.',
                metadata: [
                    'fresh_collectability' => '5',
                    'expected_oper_account' => $expectedOper,
                ],
            );

            $fromStatus = $locked->workflow_status;
            $locked->forceFill([
                'saving_account_for_loan_repayment' => $expectedOper,
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
                'last_error_message' => null,
            ])->save();

            $this->stageLogger->log(
                receivable: $locked,
                event: 'collectability_change_confirmed',
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
                description: 'IT confirmed fresh collectability 5 and branch OPER. Accounting approval requested.',
                metadata: [
                    'fresh_collectability' => '5',
                    'expected_oper_account' => $expectedOper,
                ],
                actor: $user,
                approvalRequest: $approvalRequest,
                apiLog: $apiLog,
            );

            return $locked->refresh();
        });
    }

    private function assertConfirmable(InsuranceReceivable $receivable, User $user): void
    {
        if (! $user->can('confirmCollectabilityChange', $receivable)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized IT users can confirm collectability changes.',
            ]);
        }

        if ($receivable->isLegacyOrigin() || $receivable->isTerminal()) {
            throw ValidationException::withMessages([
                'workflow_status' => 'This receivable cannot enter formation validation.',
            ]);
        }

        if ($receivable->workflow_status !== InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Receivable is not waiting for collectability confirmation.',
            ]);
        }
    }

    private function returnToBranchMaker(
        InsuranceReceivable $receivable,
        User $user,
        string $message,
        ?string $expectedOper,
        ?ApiIntegrationLog $apiLog,
    ): InsuranceReceivable {
        $fromStatus = $receivable->workflow_status;
        $actualOper = $this->operAccount->actual($receivable);

        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_REINQUIRY_REQUIRED,
            'last_error_message' => $message,
        ])->save();

        $this->stageLogger->log(
            receivable: $receivable,
            event: 'it_oper_validation_failed_returned',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            description: $message,
            metadata: [
                'fresh_collectability' => $receivable->collectability,
                'expected_oper_account' => $expectedOper,
                'actual_repayment_account' => $actualOper,
            ],
            actor: $user,
            apiLog: $apiLog,
        );

        return $receivable->refresh();
    }

    private function latestApiLog(InsuranceReceivable $receivable): ?ApiIntegrationLog
    {
        return ApiIntegrationLog::query()
            ->where('related_type', $receivable->getMorphClass())
            ->where('related_id', $receivable->getKey())
            ->latest('id')
            ->first();
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
    }
}
