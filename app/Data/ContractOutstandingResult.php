<?php

namespace App\Data;

use Brick\Math\BigDecimal;

class ContractOutstandingResult
{
    public function __construct(
        public readonly string $accountNumber,
        public readonly string $requestedAsOf,
        public readonly ?string $returnedAsOf,
        public readonly BigDecimal $bakiDebet,
        public readonly ?string $loanProduct,
        public readonly ?string $loanAccountNumber,
        public readonly ?string $loanAltNumber,
        public readonly int $apiLogId,
    ) {}
}
