<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

class PerformLoanInquiryAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceReceivable
    {
        $result = $this->coreBankingClient->inquireLoan(
            accountNumber: $insuranceReceivable->loan_account_number,
            related: $insuranceReceivable,
            requestedBy: $user,
        );

        if ($result['response_code'] !== '00') {
            throw ValidationException::withMessages([
                'loan_account_number' => $result['description'] ?: 'Loan inquiry failed.',
            ]);
        }

        $data = $result['data'];
        $branchCode = $this->stringValue($data['branchCode'] ?? null);

        if ($branchCode === null) {
            throw ValidationException::withMessages([
                'loan_account_number' => 'Loan inquiry response does not include branchCode.',
            ]);
        }

        if (! $user->hasRole('super_admin') && $user->branchOffice?->branch_code !== $branchCode) {
            throw ValidationException::withMessages([
                'loan_account_number' => "Loan branch {$branchCode} does not match your branch {$user->branchOffice?->branch_code}.",
            ]);
        }

        $branchOffice = BranchOffice::query()
            ->where('branch_code', $branchCode)
            ->first();

        if (! $branchOffice) {
            throw ValidationException::withMessages([
                'loan_account_number' => "Branch code {$branchCode} is not registered.",
            ]);
        }

        $loanOutstanding = $this->moneyValue($data['loanOutStanding'] ?? null);

        $insuranceReceivable->forceFill([
            'branch_office_id' => $branchOffice->id,
            'branch_code' => $branchCode,
            'loan_account_number' => $this->stringValue($data['accountNumber'] ?? null) ?? $insuranceReceivable->loan_account_number,
            'alt_number' => $this->stringValue($data['altNumber'] ?? null),
            'cif_no' => $this->stringValue($data['cifNo'] ?? null),
            'cif_no_alt' => $this->stringValue($data['cifNoAlt'] ?? null),
            'customer_name' => $this->stringValue($data['customerName'] ?? null),
            'collectability' => $this->stringValue($data['collectability'] ?? null),
            'dpd' => $this->integerValue($data['dpd'] ?? null),
            'product_id' => $this->stringValue($data['productID'] ?? null),
            'product_name' => $this->stringValue($data['productName'] ?? null),
            'start_period' => $this->dateValue($data['startPeriod'] ?? null),
            'end_period' => $this->dateValue($data['endPeriod'] ?? null),
            'credit_limit' => $this->moneyValue($data['creditLimit'] ?? null),
            'loan_outstanding' => $loanOutstanding,
            'receivable_amount' => $insuranceReceivable->receivable_amount ?? $loanOutstanding,
        ])->save();

        return $insuranceReceivable->refresh();
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function moneyValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return str_replace(',', '', $value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        return (string) $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $stringValue = (string) $value;

            if (preg_match('/^\d{8}$/', $stringValue) === 1) {
                return CarbonImmutable::createFromFormat('Ymd', $stringValue)->toDateString();
            }

            return CarbonImmutable::parse($stringValue)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
