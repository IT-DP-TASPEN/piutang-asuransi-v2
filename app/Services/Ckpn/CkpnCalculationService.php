<?php

namespace App\Services\Ckpn;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnCalculationRule;
use App\Services\Ckpn\Contracts\CkpnCalculationStrategy;
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
}
