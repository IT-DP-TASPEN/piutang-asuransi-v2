<?php

namespace App\Actions\CoreBanking;

use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveCoreTransactionOutcomeAction
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function handle(Model $record, string $outcome, User $user, string $notes, array $evidence): Model
    {
        if (! $this->authorized($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'Only authorized Accounting users can resolve Core transaction outcomes.',
            ]);
        }

        if (trim($notes) === '') {
            throw ValidationException::withMessages([
                'notes' => 'Resolution notes are required.',
            ]);
        }

        if ($evidence === []) {
            throw ValidationException::withMessages([
                'evidence' => 'Resolution evidence is required.',
            ]);
        }

        if (! in_array($outcome, [
            GlToGlTransaction::RESOLUTION_OUTCOME_POSTED,
            GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED,
            GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
        ], true)) {
            throw ValidationException::withMessages([
                'outcome' => 'Unsupported Core transaction resolution outcome.',
            ]);
        }

        return DB::transaction(function () use ($record, $outcome, $user, $notes, $evidence): Model {
            $locked = $record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            return match (true) {
                $locked instanceof GlToGlTransaction => $this->resolveGl($locked, $outcome, $user, $notes, $evidence),
                $locked instanceof EarlyTerminationTransaction => $this->resolveEarlyTermination($locked, $outcome, $user, $notes, $evidence),
                $locked instanceof InsuranceReceivableInstallmentRepayment => $this->resolveRepayment($locked, $outcome, $user, $notes, $evidence),
                default => throw ValidationException::withMessages(['record' => 'Unsupported Core transaction record.']),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function resolveGl(GlToGlTransaction $transaction, string $outcome, User $user, string $notes, array $evidence): GlToGlTransaction
    {
        $transaction->forceFill([
            'status' => $outcome === GlToGlTransaction::RESOLUTION_OUTCOME_POSTED
                ? GlToGlTransaction::STATUS_SUCCESS
                : ($outcome === GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED
                    ? GlToGlTransaction::STATUS_FAILED
                    : GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT),
            'resolution_status' => $outcome === GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN
                ? GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                : GlToGlTransaction::RESOLUTION_STATUS_RESOLVED,
            'resolution_outcome' => $outcome,
            'resolution_reason' => 'Manual Core outcome reconciliation.',
            'resolution_payload' => $evidence,
            'resolution_notes' => $notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return $transaction->refresh();
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function resolveEarlyTermination(EarlyTerminationTransaction $transaction, string $outcome, User $user, string $notes, array $evidence): EarlyTerminationTransaction
    {
        $transaction->forceFill([
            'status' => $outcome === EarlyTerminationTransaction::RESOLUTION_OUTCOME_POSTED
                ? EarlyTerminationTransaction::STATUS_SUCCESS
                : ($outcome === EarlyTerminationTransaction::RESOLUTION_OUTCOME_NOT_POSTED
                    ? EarlyTerminationTransaction::STATUS_FAILED
                    : EarlyTerminationTransaction::STATUS_UNKNOWN_TIMEOUT),
            'resolution_status' => $outcome === EarlyTerminationTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN
                ? EarlyTerminationTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED
                : EarlyTerminationTransaction::RESOLUTION_STATUS_RESOLVED,
            'resolution_outcome' => $outcome,
            'resolution_reason' => 'Manual Core outcome reconciliation.',
            'resolution_payload' => $evidence,
            'resolution_notes' => $notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return $transaction->refresh();
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function resolveRepayment(InsuranceReceivableInstallmentRepayment $repayment, string $outcome, User $user, string $notes, array $evidence): InsuranceReceivableInstallmentRepayment
    {
        $repayment->forceFill([
            'status' => $outcome === InsuranceReceivableInstallmentRepayment::RESOLUTION_OUTCOME_POSTED
                ? InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY
                : ($outcome === InsuranceReceivableInstallmentRepayment::RESOLUTION_OUTCOME_NOT_POSTED
                    ? InsuranceReceivableInstallmentRepayment::STATUS_FAILED
                    : InsuranceReceivableInstallmentRepayment::STATUS_RECONCILIATION_REQUIRED),
            'resolution_outcome' => $outcome,
            'resolution_payload' => $evidence,
            'resolution_notes' => $notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return $repayment->refresh();
    }

    private function authorized(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('accounting_approver');
    }
}
