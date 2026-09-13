<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveEarlyTerminationTopUpReconciliationAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(
        InsuranceReceivable $receivable,
        string $purpose,
        string $outcome,
        User $user,
        ?string $notes = null,
    ): GlToGlTransaction {
        if (! in_array($purpose, [
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
        ], true)) {
            throw ValidationException::withMessages([
                'purpose' => 'Unsupported Early Termination top up component.',
            ]);
        }

        if (! $user->can('reconcileEarlyTerminationTopUp', $receivable)) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not allowed to reconcile this Early Termination top up.',
            ]);
        }

        if (trim((string) $notes) === '') {
            throw ValidationException::withMessages([
                'reconciliation' => 'Reconciliation notes are required.',
            ]);
        }

        if (! in_array($outcome, [
            GlToGlTransaction::RESOLUTION_OUTCOME_POSTED,
            GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED,
        ], true)) {
            throw ValidationException::withMessages([
                'outcome' => 'Choose whether the exact GL transaction was posted or not posted.',
            ]);
        }

        $lock = Cache::lock("insurance-receivable:{$receivable->getKey()}:et-top-up-reconciliation", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Early Termination top up reconciliation is already being processed.',
            ]);
        }

        try {
            $transaction = $this->reconciliationTransaction($receivable, $purpose);
            $payload = $transaction->request_payload;
            $amount = is_array($payload) ? ($payload['amount'] ?? null) : null;

            if ((! is_int($amount) && ! is_string($amount)) || trim((string) $amount) === '') {
                throw ValidationException::withMessages([
                    'reconciliation' => 'Stored GL transaction amount is missing.',
                ]);
            }

            return DB::transaction(function () use ($transaction, $purpose, $outcome, $amount, $user, $notes): GlToGlTransaction {
                $locked = GlToGlTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->resolution_status !== GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED) {
                    throw ValidationException::withMessages([
                        'reconciliation' => 'Early Termination top up component no longer requires reconciliation.',
                    ]);
                }

                $locked->forceFill([
                    'resolution_status' => GlToGlTransaction::RESOLUTION_STATUS_RESOLVED_MANUALLY,
                    'resolution_outcome' => $outcome,
                    'resolution_reason' => $outcome === GlToGlTransaction::RESOLUTION_OUTCOME_POSTED
                        ? 'Exact GL transaction manually confirmed as posted.'
                        : 'Exact GL transaction manually confirmed as not posted.',
                    'resolution_payload' => [
                        'gl_to_gl_transaction_id' => $locked->id,
                        'purpose' => $purpose,
                        'reference_number' => $locked->reference_number,
                        'receipt_number' => $locked->receipt_number,
                        'idempotency_key' => $locked->idempotency_key,
                        'amount' => (string) $amount,
                        'original_status' => $locked->status,
                        'outcome' => $outcome,
                    ],
                    'resolution_notes' => $notes,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                ])->save();

                $this->stageLogger->log(
                    receivable: $locked->insuranceReceivable,
                    event: 'early_termination_top_up_reconciliation_resolved',
                    description: $notes ?: 'Early Termination top up reconciliation verified.',
                    metadata: [
                        'gl_to_gl_transaction_id' => $locked->id,
                        'purpose' => $purpose,
                        'reference_number' => $locked->reference_number,
                        'amount' => (string) $amount,
                        'outcome' => $outcome,
                    ],
                    actor: $user,
                );

                return $locked->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function reconciliationTransaction(InsuranceReceivable $receivable, string $purpose): GlToGlTransaction
    {
        return DB::transaction(function () use ($receivable, $purpose): GlToGlTransaction {
            $locked = InsuranceReceivable::query()
                ->whereKey($receivable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isLegacyOrigin() || $locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Early Termination top up cannot be reconciled for this receivable.',
                ]);
            }

            $transaction = $locked->glToGlTransactions()
                ->where('purpose', $purpose)
                ->where('resolution_status', GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $transaction instanceof GlToGlTransaction) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'No reconciliation-required component found.',
                ]);
            }

            return $transaction;
        });
    }
}
