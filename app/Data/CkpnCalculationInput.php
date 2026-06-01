<?php

namespace App\Data;

use Carbon\CarbonInterface;

class CkpnCalculationInput
{
    public function __construct(
        public readonly CkpnReceivableCandidate $candidate,
        public readonly CarbonInterface $asOfDate,
    ) {}
}
