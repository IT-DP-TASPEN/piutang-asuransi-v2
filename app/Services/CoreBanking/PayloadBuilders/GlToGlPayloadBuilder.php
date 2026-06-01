<?php

namespace App\Services\CoreBanking\PayloadBuilders;

use App\Models\CkpnJournal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

class GlToGlPayloadBuilder
{
    /**
     * @return array<string, string>
     */
    public function build(
        CkpnJournal $journal,
        string $referenceNumber,
        string $receiptNumber,
        ?CarbonInterface $dateTime = null,
    ): array {
        $journal->loadMissing(['branchOffice', 'ckpnWorkpaper.branchOffice']);

        $branchCode = $journal->branchOffice?->branch_code
            ?? $journal->ckpnWorkpaper?->branchOffice?->branch_code
            ?? '';

        return [
            'referenceNumber' => $referenceNumber,
            'trxType' => 'SAKEP CKPN',
            'termType' => '',
            'termId' => 'FINCLOUD',
            'receiptNumber' => $receiptNumber,
            'debitAccount' => $journal->debit_account ?? '',
            'creditAccount' => $journal->credit_account ?? '',
            'amount' => $this->formatAmount($journal->total_amount),
            'fee' => '0',
            'creditFee' => '0',
            'branchCode' => $branchCode,
            'debitNarrative' => $journal->debit_narrative ?? '',
            'creditNarrative' => $journal->credit_narrative ?? '',
            'customerId' => '',
            'dateTime' => ($dateTime ?? now())->format('YmdHis'),
            'description' => $journal->description ?? '',
            'debitFee' => '0',
            'destAccount' => '',
            'currency' => 'IDR',
            'srcAccType' => '10',
            'totalBill' => '',
            'type' => 'G2',
        ];
    }

    private function formatAmount(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
