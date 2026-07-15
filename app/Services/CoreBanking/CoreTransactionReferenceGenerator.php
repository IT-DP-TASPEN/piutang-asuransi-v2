<?php

namespace App\Services\CoreBanking;

class CoreTransactionReferenceGenerator
{
    public function earlyTermination(int $insuranceReceivableId, int $attemptNo): string
    {
        return $this->format('ETERM', [$insuranceReceivableId], $attemptNo);
    }

    public function earlyTerminationFlatSpread(int $insuranceReceivableId, int $attemptNo): string
    {
        return $this->format('ETLSA', [$insuranceReceivableId], $attemptNo);
    }

    public function earlyTerminationPiutang(int $insuranceReceivableId, int $attemptNo): string
    {
        return $this->format('ETPIU', [$insuranceReceivableId], $attemptNo);
    }

    public function receivablePayment(int $paymentRequestId, int $attemptNo): string
    {
        return $this->format('RCPAY', [$paymentRequestId], $attemptNo);
    }

    public function installmentRepayment(int $insuranceReceivableId, int $repaymentId, int $attemptNo): string
    {
        return $this->format('IRREP', [$insuranceReceivableId, $repaymentId], $attemptNo);
    }

    public function ckpnJournal(int $journalId, int $attemptNo): string
    {
        return $this->format('CKPNJ', [$journalId], $attemptNo);
    }

    /**
     * @param  list<int|string>  $parts
     */
    private function format(string $prefix, array $parts, int $attemptNo): string
    {
        return $prefix . implode('-', $parts) . str_pad((string) $attemptNo, 3, '0', STR_PAD_LEFT);
    }
}
