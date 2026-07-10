<?php

namespace App\Data;

class ClaimStatusCkpnTreatment
{
    public function __construct(
        public readonly string $claimStatusName,
        public readonly string $keterangan,
        public readonly string $factor,
    ) {}
}
