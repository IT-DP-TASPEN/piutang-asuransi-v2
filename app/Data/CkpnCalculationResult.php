<?php

namespace App\Data;

class CkpnCalculationResult
{
    public function __construct(
        public readonly string $insuranceCompanyWeight,
        public readonly string $ageWeight,
        public readonly string $claimStatusWeight,
        public readonly string $finalCkpnRate,
        public readonly string $ckpnAmount,
        public readonly string $calculationExplanation,
        public readonly string $appliedRuleCode,
        public readonly int $ageDays,
        public readonly ?int $ageBucketId,
        public readonly ?string $ageBucketName,
    ) {}
}
