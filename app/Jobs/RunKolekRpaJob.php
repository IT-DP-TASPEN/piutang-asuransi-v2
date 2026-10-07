<?php

namespace App\Jobs;

use App\Models\ApiIntegrationLog;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use App\Services\KolekRpa\KolekRpaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Submits a Fincloud change through Fincloud Web. Not retried automatically: the RPA
 * creates a pending change in Fincloud, so a blind retry after a lost response can
 * file a duplicate. Failures are logged and retried manually from the receivable page.
 */
class RunKolekRpaJob implements ShouldQueue
{
    use Queueable;

    public const OPERATION_OPER_ACCOUNT = 'oper_account';

    public const OPERATION_COLLECTABILITY = 'collectability';

    public const OPERATION_RESTORE_REPAYMENT_ACCOUNT = 'restore_repayment_account';

    public int $tries = 1;

    // ponytail: must stay below the queue's retry_after (90s default) or a slow RPA call gets re-reserved
    // and marked failed while still running. Up to three Fincloud Web calls (FINCLOUD_WEB_TIMEOUT each) plus
    // one 30s core banking inquiry must fit; raise DB_QUEUE_RETRY_AFTER together with either.
    public int $timeout = 85;

    public function __construct(
        public readonly int $insuranceReceivableId,
        public readonly string $operation,
        public readonly ?int $requestedById = null,
    ) {}

    public function handle(KolekRpaClient $client, OperRepaymentAccount $operAccount, InsuranceReceivableStageLogger $logger): void
    {
        $receivable = InsuranceReceivable::query()->findOrFail($this->insuranceReceivableId);

        if (! self::isApplicable($receivable, $this->operation)) {
            return;
        }

        $requestedBy = $this->requestedById ? User::query()->find($this->requestedById) : null;

        // Remember the pre-OPER (AGF) account so it can be restored after Early Termination.
        if ($this->operation === self::OPERATION_OPER_ACCOUNT && blank($receivable->original_saving_account_for_loan_repayment)) {
            $receivable->forceFill([
                'original_saving_account_for_loan_repayment' => trim((string) $receivable->saving_account_for_loan_repayment) ?: null,
            ])->saveQuietly();
        }

        try {
            $metadata = match ($this->operation) {
                self::OPERATION_OPER_ACCOUNT => $client->setRepaymentAccount(
                    $receivable->loan_account_number,
                    $operAccount->expected($receivable),
                    $receivable,
                    $requestedBy,
                ),
                self::OPERATION_COLLECTABILITY => $client->updateCollectability(
                    $receivable->loan_account_number,
                    5,
                    'Manual',
                    $receivable,
                    $requestedBy,
                ),
                self::OPERATION_RESTORE_REPAYMENT_ACCOUNT => $client->setRepaymentAccount(
                    $receivable->loan_account_number,
                    $receivable->original_saving_account_for_loan_repayment,
                    $receivable,
                    $requestedBy,
                ),
            };
        } catch (ValidationException $exception) {
            $this->logFailure($receivable, $logger, collect($exception->errors())->flatten()->first() ?: $exception->getMessage());

            return;
        }

        $receivable->forceFill(['last_error_message' => null])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: "{$this->operation}_rpa_succeeded",
            description: match ($this->operation) {
                self::OPERATION_OPER_ACCOUNT => 'RPA submitted the repayment account change to branch OPER in Fincloud. Awaiting approval in Fincloud before BM approval.',
                self::OPERATION_COLLECTABILITY => 'RPA submitted the collectability change to 5 (Manual) in Fincloud. Awaiting IT approval in Fincloud.',
                self::OPERATION_RESTORE_REPAYMENT_ACCOUNT => "RPA submitted the repayment account change back to {$receivable->original_saving_account_for_loan_repayment} in Fincloud. Awaiting approval in Fincloud.",
            },
            metadata: $metadata,
            actor: $requestedBy,
            apiLog: $this->latestApiLog($receivable),
            triggeredByType: $requestedBy instanceof User ? null : 'job',
        );
    }

    public function failed(?Throwable $exception): void
    {
        $receivable = InsuranceReceivable::query()->find($this->insuranceReceivableId);

        if ($receivable instanceof InsuranceReceivable && $exception instanceof Throwable) {
            $this->logFailure($receivable, app(InsuranceReceivableStageLogger::class), $exception->getMessage());
        }
    }

    /**
     * Whether the receivable is still at the stage this RPA operation belongs to.
     */
    public static function isApplicable(InsuranceReceivable $receivable, string $operation): bool
    {
        if ($receivable->isTerminal()) {
            return false;
        }

        return match ($operation) {
            self::OPERATION_OPER_ACCOUNT => $receivable->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED
                && ! app(OperRepaymentAccount::class)->matches($receivable),
            self::OPERATION_COLLECTABILITY => $receivable->workflow_status === InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            self::OPERATION_RESTORE_REPAYMENT_ACCOUNT => in_array($receivable->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
                InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
            ], true) && filled($receivable->original_saving_account_for_loan_repayment),
            default => false,
        };
    }

    /**
     * Manual retry is offered only after the latest attempt for this operation failed.
     */
    public static function canRetry(InsuranceReceivable $receivable, string $operation): bool
    {
        return self::isApplicable($receivable, $operation)
            && $receivable->stageLogs()
                ->whereIn('event', ["{$operation}_rpa_succeeded", "{$operation}_rpa_failed"])
                ->latest('id')
                ->value('event') === "{$operation}_rpa_failed";
    }

    private function logFailure(InsuranceReceivable $receivable, InsuranceReceivableStageLogger $logger, string $message): void
    {
        $receivable->forceFill(['last_error_message' => $message])->saveQuietly();

        $logger->log(
            receivable: $receivable,
            event: "{$this->operation}_rpa_failed",
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
            ->where('service_name', 'kolek_rpa')
            ->latest('id')
            ->first();
    }
}
