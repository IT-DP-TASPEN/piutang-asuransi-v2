<?php

namespace App\Data;

use Brick\Math\BigDecimal;

class ContractOutstandingResult
{
    public function __construct(
        public readonly string $requestedAccount,
        public readonly string $primaryAccount,
        public readonly string $requestedAsOf,
        public readonly string $returnedAsOf,
        public readonly BigDecimal $contractualOutstanding,
        public readonly BigDecimal $contractRate,
        public readonly string $positionSource,
        public readonly array $repaymentHistory,
        public readonly int $apiLogId,
    ) {}
}
