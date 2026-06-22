<?php

namespace App\Actions\InsuranceReceivable;

use App\Jobs\ExecuteEarlyTerminationJob;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueEarlyTerminationAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        User $user,
        bool $manualTopUpConfirmed = false,
    ): InsuranceReceivable {
        return DB::transaction(function () use ($insuranceReceivable, $user, $manualTopUpConfirmed): InsuranceReceivable {
            $locked = InsuranceReceivable::query()->lockForUpdate()->findOrFail($insuranceReceivable->id);
            $allowedStatuses = $manualTopUpConfirmed
                ? [InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED]
                : [
                    InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING,
                    InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
                    InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
                ];

            if ($locked->isTerminal() || ! in_array($locked->system_status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'system_status' => 'Early termination is not available for the current system status.',
                ]);
            }

            $fromStatus = $locked->system_status;
            $event = $manualTopUpConfirmed
                ? 'early_termination_manual_top_up_confirmed'
                : ($fromStatus === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING
                    ? 'early_termination_queued'
                    : 'early_termination_retry_queued');
            $description = $manualTopUpConfirmed
                ? 'Manual top up confirmed. Early termination queued without automatic balance inquiry or GL-to-GL top up.'
                : ($event === 'early_termination_queued'
                    ? 'Early termination queued after Accounting confirmation.'
                    : 'Early termination retry queued.');

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
                $manualTopUpConfirmed,
            )->afterCommit();

            return $locked->refresh();
        });
    }
}
