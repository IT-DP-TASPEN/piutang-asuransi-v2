<?php

namespace App\Services\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use Illuminate\Validation\ValidationException;

class OperRepaymentAccount
{
    public function expected(InsuranceReceivable $receivable): string
    {
        $branchCode = strtoupper(trim((string) $receivable->branch_code));

        if (preg_match('/^[A-Z0-9]{3}$/', $branchCode) !== 1) {
            throw ValidationException::withMessages([
                'branch_code' => 'A valid three-character branch code is required to derive the OPER account.',
            ]);
        }

        return $branchCode.'000OPER';
    }

    public function actual(InsuranceReceivable $receivable): string
    {
        return $this->normalize($receivable->saving_account_for_loan_repayment);
    }

    public function normalize(mixed $account): string
    {
        return strtoupper(trim((string) $account));
    }

    public function matches(InsuranceReceivable $receivable): bool
    {
        return $this->actual($receivable) === $this->expected($receivable);
    }

    public function assertMatches(InsuranceReceivable $receivable): void
    {
        if ($this->matches($receivable)) {
            return;
        }

        $expected = $this->expected($receivable);
        $actual = $this->actual($receivable);

        throw ValidationException::withMessages([
            'saving_account_for_loan_repayment' => "Repayment account must be branch OPER {$expected}; actual: ".($actual === '' ? '(empty)' : $actual).'.',
        ]);
    }
}
