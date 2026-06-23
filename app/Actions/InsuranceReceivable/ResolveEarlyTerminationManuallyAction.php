<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveEarlyTerminationManuallyAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
        private readonly CoreBankingClient $coreBankingClient,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        $lock = Cache::lock("insurance-receivable:{$insuranceReceivable->getKey()}:resolve-early-termination", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'system_status' => 'Early termination resolution verification is already in progress.',
            ]);
        }

        try {
            $eligible = DB::transaction(function () use ($insuranceReceivable): InsuranceReceivable {
                $locked = InsuranceReceivable::query()
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertEligible($locked);

                return $locked;
            });
            $loanAccountNumber = trim((string) $eligible->loan_account_number);
            $result = $this->coreBankingClient->inquireLoan(
                accountNumber: $loanAccountNumber,
                related: $eligible,
                requestedBy: $user,
            );
            $apiLog = $result['log_id'] === null
                ? null
                : ApiIntegrationLog::query()->find($result['log_id']);

            if ($result['response_code'] !== '77') {
                throw ValidationException::withMessages([
                    'loan_account_number' => $this->verificationFailureMessage($result),
                ]);
            }

            return DB::transaction(function () use (
                $insuranceReceivable,
                $user,
                $notes,
                $loanAccountNumber,
                $result,
                $apiLog,
            ): InsuranceReceivable {
                $locked = InsuranceReceivable::query()
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertEligible($locked);

                if (trim((string) $locked->loan_account_number) !== $loanAccountNumber) {
                    throw ValidationException::withMessages([
                        'loan_account_number' => 'Loan account number changed during manual Early Termination verification.',
                    ]);
                }

                $fromWorkflowStatus = $locked->workflow_status;
                $fromSystemStatus = $locked->system_status;

                $locked->forceFill([
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                    'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                    'last_error_message' => null,
                    'early_termination_resolved_at' => now(),
                ])->save();

                $this->stageLogger->log(
                    receivable: $locked,
                    event: 'early_termination_resolved_after_manual_core_execution',
                    fromStatus: $fromSystemStatus,
                    toStatus: InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                    description: $notes ?: 'Early termination was executed manually in core and verified via loan inquiry response code 77.',
                    metadata: [
                        'from_workflow_status' => $fromWorkflowStatus,
                        'to_workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
                        'from_system_status' => $fromSystemStatus,
                        'to_system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
                        'verification_response_code' => $result['response_code'],
                        'verification_response_description' => $result['description'],
                        'api_integration_log_id' => $apiLog?->id,
                        'loan_account_number' => $loanAccountNumber,
                    ],
                    actor: $user,
                    apiLog: $apiLog,
                );

                return $locked->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function assertEligible(InsuranceReceivable $insuranceReceivable): void
    {
        if (! $insuranceReceivable->canResolveEarlyTermination()) {
            throw ValidationException::withMessages([
                'system_status' => 'Only failed or manually executed Early Termination can be resolved.',
            ]);
        }

        if (trim((string) $insuranceReceivable->loan_account_number) === '') {
            throw ValidationException::withMessages([
                'loan_account_number' => 'Loan account number is required to verify manual Early Termination.',
            ]);
        }
    }

    /**
     * @param  array{response_code: string|null, description: string|null, error_message: string|null}  $result
     */
    private function verificationFailureMessage(array $result): string
    {
        if ($result['response_code'] === '00') {
            return 'Loan is still found in core banking and has not been closed. Execute Early Termination manually in core before resolving.';
        }

        return $result['description']
            ?: $result['error_message']
            ?: 'Unable to verify that the loan was closed in core banking.';
    }
}
