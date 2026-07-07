<?php

namespace App\Services\Ckpn\Strategies;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnCalculationRule;
use App\Services\Ckpn\CkpnCalculationService;
use App\Services\Ckpn\Contracts\CkpnCalculationStrategy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AverageThreeFactorsStrategy implements CkpnCalculationStrategy
{
    public function calculate(CkpnCalculationInput $input, CkpnCalculationRule $rule): CkpnCalculationResult
    {
        $candidate = $input->candidate;

        if (blank($candidate->claimStatusCode)) {
            throw ValidationException::withMessages([
                'claim_status_id' => 'Claim status is required for CKPN calculation.',
            ]);
        }

        if (blank($candidate->receivableFormationDate)) {
            throw ValidationException::withMessages([
                'receivable_formation_date' => 'Receivable formation date is required for CKPN calculation.',
            ]);
        }

        if (blank($candidate->receivableAmount)) {
            throw ValidationException::withMessages([
                'receivable_amount' => 'Receivable amount is required for CKPN calculation.',
            ]);
        }

        $formationDate = CarbonImmutable::parse($candidate->receivableFormationDate)->startOfDay();
        $asOfDate = CarbonImmutable::parse($input->asOfDate)->startOfDay();
        $ageDays = (int) $formationDate->diffInDays($asOfDate, false);

        if ($ageDays < 0) {
            throw ValidationException::withMessages([
                'period' => 'CKPN cutoff date cannot be before receivable formation date.',
            ]);
        }

        $ageBucket = CkpnCalculationService::ageBucketFor($ageDays);
        $insuranceCompanyWeight = CkpnCalculationService::scale4($candidate->insuranceCompanyWeight);
        $ageWeight = CkpnCalculationService::scale4($ageBucket->ckpn_weight);
        $claimStatusWeight = CkpnCalculationService::scale4($candidate->claimStatusWeight);

        $finalRate = BigDecimal::of($insuranceCompanyWeight)
            ->plus($ageWeight)
            ->plus($claimStatusWeight)
            ->dividedBy('3', 4, RoundingMode::HalfUp);
        $explanation = 'Average of insurance company, age bucket, and claim status weights divided by 3.';

        $ckpnAmount = BigDecimal::of($candidate->receivableAmount)
            ->multipliedBy($finalRate)
            ->dividedBy('100', 2, RoundingMode::HalfUp);

        return new CkpnCalculationResult(
            insuranceCompanyWeight: $insuranceCompanyWeight,
            ageWeight: $ageWeight,
            claimStatusWeight: $claimStatusWeight,
            finalCkpnRate: (string) $finalRate->toScale(4, RoundingMode::HalfUp),
            ckpnAmount: (string) $ckpnAmount->toScale(2, RoundingMode::HalfUp),
            calculationExplanation: $explanation,
            appliedRuleCode: $rule->code,
            ageDays: $ageDays,
            ageBucketId: $ageBucket->id,
            ageBucketName: $ageBucket->name,
        );
    }
}
