<?php

namespace Tests\Unit;

use App\Services\CoreBanking\CoreBankingSignatureGenerator;
use Tests\TestCase;

class CoreBankingSignatureGeneratorTest extends TestCase
{
    public function test_same_raw_body_produces_same_signature(): void
    {
        config(['core_banking.signature_secret' => 'secret-key']);

        $generator = new CoreBankingSignatureGenerator;
        $rawBody = '{"accountNumber":"3010001000054745"}';

        $this->assertSame($generator->generate($rawBody), $generator->generate($rawBody));
        $this->assertSame(hash_hmac('sha256', $rawBody, 'secret-key'), $generator->generate($rawBody));
    }

    public function test_different_json_formatting_produces_different_signature(): void
    {
        config(['core_banking.signature_secret' => 'secret-key']);

        $generator = new CoreBankingSignatureGenerator;
        $compactBody = '{"accountNumber":"3010001000054745"}';
        $formattedBody = "{\n  \"accountNumber\": \"3010001000054745\"\n}";

        $this->assertNotSame($generator->generate($compactBody), $generator->generate($formattedBody));
    }

    public function test_generator_signs_raw_body_string_without_hidden_serialization(): void
    {
        config(['core_banking.signature_secret' => 'secret-key']);

        $generator = new CoreBankingSignatureGenerator;
        $rawBody = '{"z":1,"a":2}';

        $this->assertSame(hash_hmac('sha256', $rawBody, 'secret-key'), $generator->generate($rawBody));
    }
}
