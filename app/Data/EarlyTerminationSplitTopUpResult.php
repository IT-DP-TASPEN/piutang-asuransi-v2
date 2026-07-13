<?php

namespace App\Data;

use Brick\Math\BigDecimal;

class EarlyTerminationSplitTopUpResult
{
    public function __construct(
        public readonly BigDecimal $fincloudOutstanding,
        public readonly BigDecimal $contractOutstanding,
        public readonly BigDecimal $availableBalance,
        public readonly BigDecimal $spread,
        public readonly BigDecimal $totalShortage,
        public readonly BigDecimal $lsaTopUpAmount,
        public readonly BigDecimal $piutangTopUpAmount,
    ) {}
}
