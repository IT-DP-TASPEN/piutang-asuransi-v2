<?php

namespace App\Jobs;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Models\EarlyTerminationTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExecuteEarlyTerminationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $insuranceReceivableId,
        public readonly ?int $requestedBy = null,
        public readonly bool $manualTopUpConfirmed = false,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("early-termination-{$this->insuranceReceivableId}"))
                ->dontRelease()
                ->expireAfter(600),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ExecuteEarlyTerminationWithRepaymentTopUpAction $action, InsuranceReceivableStageLogger $logger): void
    {
        $receivable = InsuranceReceivable::query()->findOrFail($this->insuranceReceivableId);
        $actor = $this->requestedBy ? User::query()->find($this->requestedBy) : null;
        $fromStatus = $receivable->system_status;

        if ($receivable->isTerminal()
            || in_array($receivable->system_status, [
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
            ], true)) {
            return;
        }

        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
            'last_error_message' => null,
        ])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: 'early_termination_processing',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
            description: 'Early termination processing.',
            actor: $actor,
            triggeredByType: 'job',
        );

        try {
            $transaction = $action->handle($receivable, $actor, $this->manualTopUpConfirmed);

            if (! $transaction instanceof EarlyTerminationTransaction) {
                return;
            }

            if ($transaction->status === EarlyTerminationTransaction::STATUS_SUCCESS) {
                $successfulFromStatus = $receivable->system_status;
                $receivable->forceFill([
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
                    'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED,
                    'last_error_message' => null,
                    'early_termination_executed_at' => now(),
                ])->saveQuietly();

                $logger->log(
                    receivable: $receivable,
                    event: 'early_termination_executed',
                    fromStatus: $successfulFromStatus,
                    toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED,
                    description: 'Early termination executed.',
                    metadata: ['transaction_id' => $transaction->id],
                    actor: $actor,
                    triggeredByType: 'job',
                );

                return;
            }

            $this->markFailed($receivable, $logger, $transaction->response_description ?: 'Early termination failed.', $actor);
        } catch (Throwable $exception) {
            $this->markFailed($receivable, $logger, $exception->getMessage(), $actor);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $receivable = InsuranceReceivable::query()->find($this->insuranceReceivableId);

        if (! $receivable instanceof InsuranceReceivable || ! $exception instanceof Throwable) {
            return;
        }

        $this->markFailed($receivable, app(InsuranceReceivableStageLogger::class), $exception->getMessage(), null);
    }

    private function markFailed(
        InsuranceReceivable $receivable,
        InsuranceReceivableStageLogger $logger,
        string $message,
        ?User $actor,
    ): void {
        if ($receivable->isTerminal()
            || in_array($receivable->system_status, [
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            ], true)) {
            return;
        }

        $fromStatus = $receivable->system_status;
        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            'last_error_message' => $message,
        ])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: 'early_termination_failed',
            fromStatus: $fromStatus,
            toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            description: $message,
            actor: $actor,
            triggeredByType: 'job',
        );
    }
}
