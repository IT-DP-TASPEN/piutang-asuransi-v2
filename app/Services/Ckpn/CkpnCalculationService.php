<?php

namespace App\Services\Ckpn;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnAgeBucket;
use App\Models\CkpnCalculationRule;
use App\Services\Ckpn\Contracts\CkpnCalculationStrategy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class CkpnCalculationService
{
    public function calculate(CkpnCalculationInput $input): CkpnCalculationResult
    {
        $rule = CkpnCalculationRule::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $rule instanceof CkpnCalculationRule) {
            throw ValidationException::withMessages([
                'ckpn_rule' => 'Active CKPN calculation rule not found.',
            ]);
        }

        if (! $rule->strategy_class) {
            throw ValidationException::withMessages([
                'ckpn_rule' => 'Active CKPN calculation rule does not define a strategy class.',
            ]);
        }

        $strategy = app($rule->strategy_class);

        if (! $strategy instanceof CkpnCalculationStrategy) {
            throw ValidationException::withMessages([
                'ckpn_rule' => "CKPN strategy {$rule->strategy_class} is invalid.",
            ]);
        }

        return $strategy->calculate($input, $rule);
    }

    public static function ageBucketFor(int $ageDays): CkpnAgeBucket
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

    public static function scale4(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(4, RoundingMode::HalfUp);
    }
}
