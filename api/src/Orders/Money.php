<?php

declare(strict_types=1);

namespace DevisCrm\Api\Orders;

use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Validation\Fields;

/**
 * Order money in whole kopecks, the same rules as src/lib/services/money.ts
 * (shared cases: src/lib/services/money.cases.json):
 *   line total = quantity × unit price rounded to the nearest kopeck, halves up, where the
 *   quantity is taken as the decimal number JavaScript prints for it (1.005, not the binary
 *   1.00499999999999989…) and the product is exact: 1.005 × 100 = 100.5 -> 101;
 *   total = sum of line totals − discount, the discount never exceeding that sum.
 * Every amount stays within Number.MAX_SAFE_INTEGER, where kopecks are exact in the browser.
 * Plain string arithmetic: bcmath/gmp are not guaranteed on the hosting.
 */
final class Money
{
    private const MAX_SAFE_DIGITS = '9007199254740991';

    public static function lineTotal(int|float $quantity, int $unitPriceMinor): int
    {
        if (!is_finite((float) $quantity) || $quantity <= 0) {
            throw Fields::invalid('quantity', 'must be a number greater than zero');
        }
        if ($unitPriceMinor < 0 || $unitPriceMinor > Fields::MAX_SAFE_INTEGER) {
            throw Fields::invalid('unitPriceMinor', 'is out of range');
        }

        [$digits, $exponent] = self::decimalParts((float) $quantity);
        $product = self::multiply($digits, (string) $unitPriceMinor);
        if ($product === '0') {
            return 0;
        }

        if ($exponent >= 0) {
            if ($exponent > strlen(self::MAX_SAFE_DIGITS)) {
                throw self::outOfRange();
            }
            $total = $product . str_repeat('0', $exponent);
        } else {
            $scale = -$exponent;
            $padded = str_pad($product, $scale + 1, '0', STR_PAD_LEFT);
            $total = ltrim(substr($padded, 0, -$scale), '0');
            $total = $total === '' ? '0' : $total;
            if ($padded[strlen($padded) - $scale] >= '5') {
                $total = self::increment($total);
            }
        }

        if (strlen($total) > strlen(self::MAX_SAFE_DIGITS)
            || (strlen($total) === strlen(self::MAX_SAFE_DIGITS) && strcmp($total, self::MAX_SAFE_DIGITS) > 0)) {
            throw self::outOfRange();
        }

        return (int) $total;
    }

    /**
     * @param list<array{quantity: int|float, unitPriceMinor: int}> $items
     * @return array{lineTotals: list<int>, subtotal: int, total: int}
     */
    public static function totals(array $items, int $discountMinor): array
    {
        $lineTotals = [];
        $subtotal = 0;
        foreach ($items as $item) {
            $line = self::lineTotal($item['quantity'], $item['unitPriceMinor']);
            $lineTotals[] = $line;
            $subtotal += $line;
            if ($subtotal > Fields::MAX_SAFE_INTEGER) {
                throw self::outOfRange();
            }
        }
        if ($discountMinor > $subtotal) {
            throw new ApiException(400, 'discount_exceeds_subtotal', 'Discount cannot exceed the sum of the items');
        }

        return ['lineTotals' => $lineTotals, 'subtotal' => $subtotal, 'total' => $subtotal - $discountMinor];
    }

    /**
     * The shortest decimal that reads back as the same double, i.e. the digits of
     * String(quantity) in JavaScript: quantity = digits × 10^exponent.
     *
     * @return array{0: string, 1: int}
     */
    private static function decimalParts(float $quantity): array
    {
        for ($precision = 0; $precision < 17; $precision++) {
            $text = sprintf('%.' . $precision . 'e', $quantity);
            if ((float) $text === $quantity) {
                break;
            }
        }
        if (preg_match('/\A(\d)(?:\.(\d+))?e([+-]\d+)\z/', $text, $match) !== 1) {
            throw new \LogicException('Unexpected float format');
        }
        $fraction = $match[2] ?? '';

        return [$match[1] . $fraction, (int) $match[3] - strlen($fraction)];
    }

    /** Product of two non-negative decimal integers given as digit strings. */
    private static function multiply(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $digits[$position] + (int) $left[$i] * (int) $right[$j];
                $digits[$position] = $sum % 10;
                $digits[$position - 1] += intdiv($sum, 10);
            }
        }
        $product = ltrim(implode('', $digits), '0');

        return $product === '' ? '0' : $product;
    }

    private static function increment(string $number): string
    {
        $index = strlen($number) - 1;
        while ($index >= 0 && $number[$index] === '9') {
            $number[$index] = '0';
            $index--;
        }

        return $index < 0 ? '1' . $number : substr_replace($number, (string) ((int) $number[$index] + 1), $index, 1);
    }

    private static function outOfRange(): ApiException
    {
        return new ApiException(400, 'amount_out_of_range', 'An amount is too large');
    }
}
