<?php

namespace App\Services\Ckpn\Contracts;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnCalculationRule;

interface CkpnCalculationStrategy
{
    public function calculate(CkpnCalculationInput $input, CkpnCalculationRule $rule): CkpnCalculationResult;
}
