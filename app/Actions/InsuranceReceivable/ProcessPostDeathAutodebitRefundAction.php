<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepaymentAttempt;
use App\Models\User;
use App\Services\ContractOutstanding\ContractOutstandingClient;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * The debtor's obligation ends on the date of death, so every loan payment posted on or after it
 * goes back to the debtor's saving account through the product's LSA GL. Payments funded by our
 * own OPER installment repayment are excluded by their Core journal id.
 */
class ProcessPostDeathAutodebitRefundAction
{
    public function __construct(
        private readonly ContractOutstandingClient $contractOutstandingClient,
        private readonly ExecuteEarlyTerminationRepaymentTopUpAction $glAction,
        private readonly OperRepaymentAccount $operAccount,
    ) {}

    public function handle(InsuranceReceivable $receivable, User $user): void
    {
        if ($receivable->date_of_death === null) {
            return;
        }

        $log = ApiIntegrationLog::query()->find($receivable->contract_outstanding_api_log_id);

        if (! $log instanceof ApiIntegrationLog) {
            $this->fail('Contract Outstanding response is required to check post-death payments.');
        }

        $deathDate = $receivable->date_of_death->toDateString();
        $ownJournals = $this->ownRepaymentJournals($receivable);
        $payments = collect($this->contractOutstandingClient->repaymentHistoryFromLog($log))
            ->filter(fn (array $payment): bool => $payment['payment_date'] >= $deathDate
                && ! in_array($payment['journal_number'], $ownJournals, true));

        if ($payments->isEmpty()) {
            return;
        }

        if ($payments->contains(fn (array $payment): bool => BigDecimal::of($payment['total_payment'])->isNegative())) {
            $this->fail('Post-death repayment history contains a reversal; reconcile the refund manually.');
        }

        $trxType = trim((string) $receivable->contract_outstanding_trx_type);

        if ($trxType === '') {
            $this->fail('Post-death refund requires a mapped LSA transaction type.');
        }

        $destAccount = $this->savingAccount($receivable);

        foreach ($payments as $payment) {
            if (BigDecimal::of($payment['total_payment'])->isZero()) {
                continue;
            }

            $transaction = $this->glAction->handlePostDeathAutodebitRefund(
                insuranceReceivable: $receivable,
                journalNumber: $payment['journal_number'],
                amount: $payment['total_payment'],
                trxType: $trxType,
                destAccount: $destAccount,
                user: $user,
            );

            if (! $transaction->isSatisfied()) {
                $this->fail("Post-death refund for journal {$payment['journal_number']} did not post: ".($transaction->response_description ?: $transaction->status).'.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function ownRepaymentJournals(InsuranceReceivable $receivable): array
    {
        return InsuranceReceivableInstallmentRepaymentAttempt::query()
            ->whereHas('repayment', fn ($query) => $query->where('insurance_receivable_id', $receivable->id))
            ->get()
            ->map(fn (InsuranceReceivableInstallmentRepaymentAttempt $attempt): ?string => $attempt->response_payload['data']['journalId'] ?? null)
            ->filter()
            ->map(fn (mixed $journal): string => (string) $journal)
            ->values()
            ->all();
    }

    private function savingAccount(InsuranceReceivable $receivable): string
    {
        $original = trim((string) $receivable->original_saving_account_for_loan_repayment);

        if ($original !== '') {
            return $original;
        }

        $current = trim((string) $receivable->saving_account_for_loan_repayment);

        if ($current !== '' && ! $this->operAccount->matches($receivable)) {
            return $current;
        }

        $this->fail('Debtor saving account is unknown; post-death refund needs manual handling.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['post_death_refund' => $message]);
    }
}
