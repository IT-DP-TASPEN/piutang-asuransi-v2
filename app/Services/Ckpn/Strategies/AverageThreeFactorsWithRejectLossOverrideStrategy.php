<?php

namespace App\Services\Ckpn\Strategies;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnAgeBucket;
use App\Models\CkpnCalculationRule;
use App\Services\Ckpn\Contracts\CkpnCalculationStrategy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AverageThreeFactorsWithRejectLossOverrideStrategy implements CkpnCalculationStrategy
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
                'period' => 'CKPN period cannot be before receivable formation date.',
            ]);
        }

        $ageBucket = $this->ageBucketFor($ageDays);
        $insuranceCompanyWeight = $this->scale4($candidate->insuranceCompanyWeight);
        $ageWeight = $this->scale4($ageBucket->ckpn_weight);
        $claimStatusWeight = $this->scale4($candidate->claimStatusWeight);

        $isRejectLossOverride = $ageDays > 365 && $candidate->claimStatusCode === 'reject_loss';

        if ($isRejectLossOverride) {
            $finalRate = BigDecimal::of('100')->toScale(4, RoundingMode::HALF_UP);
            $explanation = 'Reject Loss with age greater than 365 days: CKPN rate overridden to 100%.';
        } else {
            $finalRate = BigDecimal::of($insuranceCompanyWeight)
                ->plus($ageWeight)
                ->plus($claimStatusWeight)
                ->dividedBy('3', 4, RoundingMode::HALF_UP);
            $explanation = 'Average of insurance company, age bucket, and claim status weights divided by 3.';
        }

        $ckpnAmount = BigDecimal::of($candidate->receivableAmount)
            ->multipliedBy($finalRate)
            ->dividedBy('100', 2, RoundingMode::HALF_UP);

        return new CkpnCalculationResult(
            insuranceCompanyWeight: $insuranceCompanyWeight,
            ageWeight: $ageWeight,
            claimStatusWeight: $claimStatusWeight,
            finalCkpnRate: (string) $finalRate->toScale(4, RoundingMode::HALF_UP),
            ckpnAmount: (string) $ckpnAmount->toScale(2, RoundingMode::HALF_UP),
            calculationExplanation: $explanation,
            appliedRuleCode: $rule->code,
            ageDays: $ageDays,
            ageBucketId: $ageBucket->id,
            ageBucketName: $ageBucket->name,
        );
    }

    private function ageBucketFor(int $ageDays): CkpnAgeBucket
    {
        $ageBucket = CkpnAgeBucket::query()
            ->where('is_active', true)
            ->where(function ($query) use ($ageDays): void {
                $query->whereNull('min_days')
                    ->orWhere('min_days', '<=', $ageDays);
            })
            ->where(function ($query) use ($ageDays): void {
                $query->whereNull('max_days')
                    ->orWhere('max_days', '>=', $ageDays);
            })
            ->orderBy('min_days')
            ->first();

        if (! $ageBucket instanceof CkpnAgeBucket) {
            throw ValidationException::withMessages([
                'age_days' => "No active CKPN age bucket matches {$ageDays} days.",
            ]);
        }

        return $ageBucket;
    }

    private function scale4(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(4, RoundingMode::HALF_UP);
    }
}
