<?php

namespace App\Jobs;

use App\Actions\InsuranceReceivable\AutoSubmitInsuranceReceivableForInitialApprovalAction;
use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Models\ApiIntegrationLog;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunLoanInquiryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $insuranceReceivableId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        PerformLoanInquiryAction $action,
        AutoSubmitInsuranceReceivableForInitialApprovalAction $autoSubmitAction,
        InsuranceReceivableStageLogger $logger,
    ): void {
        $receivable = InsuranceReceivable::query()->findOrFail($this->insuranceReceivableId);
        $creator = User::query()->find($receivable->created_by);
        $fromStatus = $receivable->system_status;

        if ($receivable->isTerminal() || ! in_array($receivable->workflow_status, [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ], true)) {
            return;
        }

        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
            'last_error_message' => null,
        ])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: 'inquiry_processing',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
            description: 'Loan inquiry processing.',
            triggeredByType: 'job',
        );

        try {
            if (! $creator instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => 'Receivable creator is required for branch validation.',
                ]);
            }

            $receivable = $action->handle($receivable, $creator);
            $apiLog = $this->latestApiLog($receivable);

            $receivable->forceFill([
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
                'last_error_message' => null,
                'inquiry_completed_at' => now(),
            ])->saveQuietly();

            $logger->log(
                receivable: $receivable,
                event: 'inquiry_completed',
                fromStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
                toStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
                description: 'Loan inquiry completed.',
                apiLog: $apiLog,
                triggeredByType: 'job',
            );

            $logger->log(
                receivable: $receivable,
                event: 'branch_validation_passed',
                fromStatus: null,
                toStatus: $receivable->branch_code,
                description: 'Branch validation passed.',
                apiLog: $apiLog,
                triggeredByType: 'job',
            );

            try {
                $autoSubmitAction->handle($receivable, $creator);
            } catch (ValidationException $exception) {
                $logger->log(
                    receivable: $receivable,
                    event: 'auto_initial_approval_submission_skipped',
                    fromStatus: $receivable->workflow_status,
                    toStatus: $receivable->workflow_status,
                    description: $this->validationMessage($exception),
                    triggeredByType: 'job',
                );
            }
        } catch (ValidationException $exception) {
            $this->markFailed($receivable, $logger, $this->validationMessage($exception), $this->isBranchMismatch($exception));
        } catch (Throwable $exception) {
            $this->markFailed($receivable, $logger, $exception->getMessage(), false);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $receivable = InsuranceReceivable::query()->find($this->insuranceReceivableId);

        if (! $receivable instanceof InsuranceReceivable || ! $exception instanceof Throwable) {
            return;
        }

        $this->markFailed($receivable, app(InsuranceReceivableStageLogger::class), $exception->getMessage(), false);
    }

    private function markFailed(
        InsuranceReceivable $receivable,
        InsuranceReceivableStageLogger $logger,
        string $message,
        bool $branchMismatch,
    ): void {
        $receivable->refresh();

        if ($receivable->isTerminal() && ! $branchMismatch) {
            return;
        }

        $status = $branchMismatch
            ? InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED
            : InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED;
        $event = $branchMismatch ? 'branch_validation_failed' : 'inquiry_failed';
        $fromWorkflowStatus = $receivable->workflow_status;

        $updates = [
            'system_status' => $status,
            'last_error_message' => $message,
        ];

        if ($branchMismatch) {
            $updates['workflow_status'] = InsuranceReceivable::WORKFLOW_STATUS_CANCELLED;
        }

        $receivable->forceFill($updates)->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: $event,
            fromStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
            toStatus: $status,
            description: $branchMismatch
                ? "Branch validation failed. {$message}"
                : $message,
            metadata: $branchMismatch ? [
                'from_workflow_status' => $fromWorkflowStatus,
                'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
            ] : [],
            apiLog: $this->latestApiLog($receivable),
            triggeredByType: 'job',
        );
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
        return collect($exception->errors())
            ->flatten()
            ->first() ?: $exception->getMessage();
    }

    private function isBranchMismatch(ValidationException $exception): bool
    {
        $message = $this->validationMessage($exception);

        return str_contains($message, 'does not match receivable branch')
            || str_contains($message, 'does not match your branch');
    }
}
