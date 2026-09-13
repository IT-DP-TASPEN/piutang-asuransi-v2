<?php

namespace App\Actions\InsuranceReceivable;

use App\Jobs\ExecuteEarlyTerminationJob;
use App\Models\EarlyTerminationTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueEarlyTerminationAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        User $user,
    ): InsuranceReceivable {
        return DB::transaction(function () use ($insuranceReceivable, $user): InsuranceReceivable {
            $locked = InsuranceReceivable::query()->lockForUpdate()->findOrFail($insuranceReceivable->id);
            $allowedStatuses = [
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
                InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            ];

            if ($locked->isLegacyOrigin()) {
                throw ValidationException::withMessages([
                    'origin_type' => 'Legacy receivables cannot enter Early Termination.',
                ]);
            }

            if ($locked->isTerminal() || ! in_array($locked->system_status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'system_status' => 'Early termination is not available for the current system status.',
                ]);
            }

            $this->operAccount->assertMatches($locked);
            $locked->forceFill([
                'saving_account_for_loan_repayment' => $this->operAccount->expected($locked),
            ])->saveQuietly();

            $latestEtAttempt = $locked->earlyTerminationTransactions()->latest('id')->first();

            if ($locked->system_status === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED
                && $latestEtAttempt instanceof EarlyTerminationTransaction
                && ! $latestEtAttempt->canRetry()) {
                throw ValidationException::withMessages([
                    'early_termination' => 'Early Termination requires reconciliation before retry.',
                ]);
            }

            $fromStatus = $locked->system_status;
            $event = $fromStatus === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING
                ? 'early_termination_queued'
                : 'early_termination_retry_queued';
            $description = $event === 'early_termination_queued'
                ? 'Early termination queued automatically after Accounting approval.'
                : 'Early termination retry queued.';

            $locked->forceFill([
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
                'last_error_message' => null,
            ])->saveQuietly();

            $this->stageLogger->log(
                receivable: $locked,
                event: $event,
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
                description: $description,
                actor: $user,
            );

            ExecuteEarlyTerminationJob::dispatch(
                $locked->id,
                $user->id,
            )->afterCommit();

            return $locked->refresh();
        });
    }
}
