<?php

namespace App\Jobs;

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

    public function handle(PerformLoanInquiryAction $action, InsuranceReceivableStageLogger $logger): void
    {
        $receivable = InsuranceReceivable::query()->findOrFail($this->insuranceReceivableId);
        $creator = User::query()->find($receivable->created_by);
        $fromStatus = $receivable->system_status;

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
        $status = $branchMismatch
            ? InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED
            : InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED;
        $event = $branchMismatch ? 'branch_validation_failed' : 'inquiry_failed';

        $receivable->forceFill([
            'system_status' => $status,
            'last_error_message' => $message,
        ])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: $event,
            fromStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
            toStatus: $status,
            description: $message,
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
        return str_contains($this->validationMessage($exception), 'does not match your branch');
    }
}
