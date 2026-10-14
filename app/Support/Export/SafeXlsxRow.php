<?php

namespace App\Support\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * OpenSpout turns any string starting with "=" into a formula cell; customer or Core data must stay text.
 */
class SafeXlsxRow
{
    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, Style>  $columnStyles
     */
    public static function fromValues(array $values, array $columnStyles = []): Row
    {
        $cells = array_map(
            static fn (mixed $value, int|string $key): Cell => is_string($value) && str_starts_with($value, '=')
                ? new StringCell($value, $columnStyles[$key] ?? null)
                : Cell::fromValue($value, $columnStyles[$key] ?? null),
            $values,
            array_keys($values),
        );

        return new Row($cells);
    }
}
