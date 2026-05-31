<?php

namespace App\Data;

use App\Models\InsuranceReceivable;
use Carbon\CarbonInterface;

class CkpnCalculationInput
{
    public function __construct(
        public readonly InsuranceReceivable $insuranceReceivable,
        public readonly CarbonInterface $asOfDate,
    ) {}
}
