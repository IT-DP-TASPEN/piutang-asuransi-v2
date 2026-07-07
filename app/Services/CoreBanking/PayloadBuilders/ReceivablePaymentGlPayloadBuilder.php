<?php

namespace App\Services\CoreBanking\PayloadBuilders;

use App\Models\ReceivablePaymentRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ReceivablePaymentGlPayloadBuilder
{
    /**
     * @return array<string, string>
     */
    public function build(
        ReceivablePaymentRequest $request,
        string $referenceNumber,
        string $receiptNumber,
        ?CarbonInterface $dateTime = null,
    ): array {
        $request->loadMissing('insuranceReceivable.branchOffice');
        $receivable = $request->insuranceReceivable;
        $branchCode = $receivable?->branchOffice?->branch_code
            ?? $receivable?->branch_code
            ?? '';
        $description = 'Insurance receivable payment';
        $loanAccount = trim((string) $receivable?->loan_account_number);
        $narrative = $loanAccount === '' ? $description : "{$description} {$loanAccount}";

        $payload = [
            'referenceNumber' => $referenceNumber,
            'trxType' => $this->trxType($request),
            'termType' => '',
            'termId' => 'FINCLOUD',
            'receiptNumber' => $receiptNumber,
            'debitAccount' => '',
            'creditAccount' => '',
            'amount' => (string) BigDecimal::of($request->amount)->toScale(2, RoundingMode::HalfUp),
            'fee' => '0',
            'creditFee' => '0',
            'branchCode' => $branchCode,
            'debitNarrative' => $narrative,
            'creditNarrative' => $narrative,
            'customerId' => '',
            'dateTime' => ($dateTime ?? now())->format('YmdHis'),
            'description' => $description,
            'debitFee' => '0',
            'destAccount' => '',
            'currency' => 'IDR',
            'srcAccType' => '10',
            'totalBill' => '',
            'type' => 'G2',
        ];

        if ($request->payment_source === ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING) {
            $payload['sourceAccount'] = trim((string) $request->saving_account_number);
        }

        return $payload;
    }

    private function trxType(ReceivablePaymentRequest $request): string
    {
        return match ($request->payment_source) {
            ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING => 'RPDS01',
            ReceivablePaymentRequest::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02 => 'RPG01',
            default => throw ValidationException::withMessages([
                'payment_source' => 'Unsupported payment source.',
            ]),
        };
    }
}
