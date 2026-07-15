<?php

namespace App\Services\CoreBanking;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

class CoreBusinessPayloadComparator
{
    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $fresh
     */
    public function same(array $stored, array $fresh): bool
    {
        return $this->normalized($stored) === $this->normalized($fresh);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalized(array $payload): array
    {
        foreach (['trxReference', 'referenceNumber', 'receiptNumber', 'dateTime'] as $key) {
            unset($payload[$key]);
        }

        foreach (['amount', 'paymentAmount', 'principalPaid', 'interestPaid', 'penaltyPaid', 'principalWaive', 'interestWaive'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                try {
                    $payload[$key] = (string) BigDecimal::of((string) $payload[$key])->toScale(2, RoundingMode::HalfUp);
                } catch (Throwable) {
                    $payload[$key] = '__MALFORMED_AMOUNT__';
                }
            }
        }

        ksort($payload);

        return $payload;
    }
}
