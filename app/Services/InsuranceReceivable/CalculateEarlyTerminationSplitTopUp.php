<?php

namespace App\Services\InsuranceReceivable;

use App\Data\EarlyTerminationSplitTopUpResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class CalculateEarlyTerminationSplitTopUp
{
    public function handle(mixed $fincloudOutstanding, mixed $contractOutstanding): EarlyTerminationSplitTopUpResult
    {
        $f = $this->decimal($fincloudOutstanding, 'Fincloud outstanding');
        $c = $this->decimal($contractOutstanding, 'Contract outstanding');

        if ($c->isLessThanOrEqualTo('0')) {
            throw new InvalidArgumentException('Contract outstanding must be greater than zero.');
        }

        if ($c->isGreaterThan($f)) {
            throw new InvalidArgumentException('Contract outstanding cannot exceed fresh Fincloud outstanding.');
        }

        $spread = $f->minus($c);

        return new EarlyTerminationSplitTopUpResult(
            fincloudOutstanding: $f,
            contractOutstanding: $c,
            spread: $spread,
            totalFundingAmount: $f,
            lsaTopUpAmount: $spread,
            piutangTopUpAmount: $c,
        );
    }

    private function decimal(mixed $value, string $label): BigDecimal
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        if ($value instanceof BigDecimal) {
            return $value->toScale(2, RoundingMode::HalfUp);
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("{$label} must be a decimal string.");
        }

        try {
            return BigDecimal::of(str_replace(',', '', trim((string) $value)))->toScale(2, RoundingMode::HalfUp);
        } catch (\Throwable) {
            throw new InvalidArgumentException("{$label} must be numeric.");
        }
    }
}
