<?php

namespace App\Services\CoreBanking;

class CoreBankingSignatureGenerator
{
    public function generate(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('core_banking.signature_secret'));
    }
}
