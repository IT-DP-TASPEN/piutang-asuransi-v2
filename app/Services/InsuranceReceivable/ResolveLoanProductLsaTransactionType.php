<?php

namespace App\Services\InsuranceReceivable;

class ResolveLoanProductLsaTransactionType
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        '301' => 'LSA01',
        '302' => 'LSA02',
        '303' => 'LSA03',
        '304' => 'LSA04',
        '305' => 'LSA05',
        '306' => 'LSA06',
        '307' => 'LSA07',
        '312' => 'LSA09',
        '313' => 'LSA10',
        '314' => 'LSA11',
        '315' => 'LSA12',
        '318' => 'LSA15',
        '319' => 'LSA16',
        '320' => 'LSA17',
        '321' => 'LSA18',
        '322' => 'LSA20',
    ];

    /**
     * @return array{product_code: string, trx_type: string}|null
     */
    public function resolve(?string $product): ?array
    {
        $code = $this->productCode($product);

        if ($code === null || ! array_key_exists($code, self::MAP)) {
            return null;
        }

        return [
            'product_code' => $code,
            'trx_type' => self::MAP[$code],
        ];
    }

    public function productCode(?string $product): ?string
    {
        if ($product === null || trim($product) === '') {
            return null;
        }

        if (preg_match('/^\s*(\d{3})\b/', $product, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    public function mappings(): array
    {
        return self::MAP;
    }
}
