<?php

namespace App\Services\CoreBanking\PayloadBuilders;

use App\Models\InsuranceReceivable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

class EarlyTerminationRepaymentTopUpPayloadBuilder
{
    /**
     * @return array<string, string>
     */
    public function build(
        InsuranceReceivable $insuranceReceivable,
        string $amount,
        string $referenceNumber,
        string $receiptNumber,
        ?CarbonInterface $dateTime = null,
    ): array {
        $insuranceReceivable->loadMissing('branchOffice');

        $branchCode = $insuranceReceivable->branchOffice?->branch_code
            ?? $insuranceReceivable->branch_code
            ?? '';
        $description = 'Top up repayment account before early termination';
        $loanAccount = trim((string) $insuranceReceivable->loan_account_number);
        $narrative = $loanAccount === '' ? $description : "{$description} {$loanAccount}";

        return [
            'referenceNumber' => $referenceNumber,
            'trxType' => 'DISTRIBUSIDAPEM',
            'termType' => '',
            'termId' => 'FINCLOUD',
            'receiptNumber' => $receiptNumber,
            'debitAccount' => '',
            'creditAccount' => '',
            'amount' => (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp),
            'fee' => '0',
            'creditFee' => '0',
            'branchCode' => $branchCode,
            'debitNarrative' => $narrative,
            'creditNarrative' => $narrative,
            'customerId' => '',
            'dateTime' => ($dateTime ?? now())->format('YmdHis'),
            'description' => $description,
            'debitFee' => '0',
            'destAccount' => trim((string) $insuranceReceivable->saving_account_for_loan_repayment),
            'currency' => 'IDR',
            'srcAccType' => '10',
            'totalBill' => '',
            'type' => 'G2',
        ];
    }
}
