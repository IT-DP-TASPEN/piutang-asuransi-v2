<?php

namespace App\Data;

use Brick\Math\BigDecimal;

class EarlyTerminationSplitTopUpResult
{
    public function __construct(
        public readonly BigDecimal $fincloudOutstanding,
        public readonly BigDecimal $contractOutstanding,
        public readonly BigDecimal $spread,
        public readonly BigDecimal $totalFundingAmount,
        public readonly BigDecimal $lsaTopUpAmount,
        public readonly BigDecimal $piutangTopUpAmount,
    ) {}
}
