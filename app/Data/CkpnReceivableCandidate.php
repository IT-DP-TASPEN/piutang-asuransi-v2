<?php

namespace App\Data;

use App\Models\InsuranceReceivable;
use App\Models\LegacyReceivable;

class CkpnReceivableCandidate
{
    public function __construct(
        public readonly string $receivableType,
        public readonly int $receivableId,
        public readonly int $branchOfficeId,
        public readonly string $branchCode,
        public readonly string $branchName,
        public readonly ?string $cif,
        public readonly string $loanAccountNumber,
        public readonly ?string $customerName,
        public readonly int $insuranceCompanyId,
        public readonly string $insuranceCompanyName,
        public readonly string $insuranceCompanyWeight,
        public readonly int $claimStatusId,
        public readonly string $claimStatusCode,
        public readonly string $claimStatusName,
        public readonly string $claimStatusWeight,
        public readonly string $receivableFormationDate,
        public readonly string $receivableAmount,
        public readonly ?string $dateOfDeath = null,
        public readonly ?string $creditLimit = null,
        public readonly ?string $loanOutstanding = null,
        public readonly ?string $startPeriod = null,
        public readonly ?string $endPeriod = null,
        public readonly ?string $alternateLoanAccountNumber = null,
    ) {}

    public function sourceLabel(): string
    {
        return match ($this->receivableType) {
            InsuranceReceivable::class => 'Current',
            LegacyReceivable::class => 'Legacy',
            default => class_basename($this->receivableType),
        };
    }
}
