<?php

namespace App\Services\InsuranceReceivable;

use App\Data\EarlyTerminationSplitTopUpResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class CalculateEarlyTerminationSplitTopUp
{
    public function handle(mixed $fincloudOutstanding, mixed $contractOutstanding, mixed $availableBalance): EarlyTerminationSplitTopUpResult
    {
        $f = $this->decimal($fincloudOutstanding, 'Fincloud outstanding');
        $c = $this->decimal($contractOutstanding, 'Contract outstanding');
        $a = $this->decimal($availableBalance, 'Available balance');

        if ($c->isLessThanOrEqualTo('0')) {
            throw new InvalidArgumentException('Contract outstanding must be greater than zero.');
        }

        if ($c->isGreaterThan($f)) {
            throw new InvalidArgumentException('Contract outstanding cannot exceed fresh Fincloud outstanding.');
        }

        $spread = $f->minus($c);
        $totalShortage = $this->max($f->minus($a), '0');
        $lsaTopUp = $this->max($spread->minus($a), '0');
        $piutangTopUp = $this->max($totalShortage->minus($lsaTopUp), '0');

        if ($piutangTopUp->isGreaterThan($c)) {
            throw new InvalidArgumentException('Piutang top up cannot exceed contract outstanding.');
        }

        if (! $lsaTopUp->plus($piutangTopUp)->isEqualTo($totalShortage)) {
            throw new InvalidArgumentException('Split top up invariant failed.');
        }

        return new EarlyTerminationSplitTopUpResult(
            fincloudOutstanding: $f,
            contractOutstanding: $c,
            availableBalance: $a,
            spread: $spread,
            totalShortage: $totalShortage,
            lsaTopUpAmount: $lsaTopUp,
            piutangTopUpAmount: $piutangTopUp,
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

    private function max(BigDecimal $left, string $right): BigDecimal
    {
        return $left->isLessThan($right) ? BigDecimal::of($right)->toScale(2) : $left->toScale(2, RoundingMode::HalfUp);
    }
}
