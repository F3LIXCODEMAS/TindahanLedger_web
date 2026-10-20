<?php

namespace App\Support;

final class Money
{
    public static function toCents(float|int|string $amount): int
    {
        $amount = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)) {
            throw new \InvalidArgumentException('Amounts must be positive and have no more than two decimal places.');
        }

        [$wholeUnits, $fractionalUnits] = array_pad(explode('.', $amount, 2), 2, '');
        $wholeUnits = ltrim($wholeUnits, '0') ?: '0';

        if (strlen($wholeUnits) > 10 || (strlen($wholeUnits) === 10 && strcmp($wholeUnits, '9999999999') > 0)) {
            throw new \InvalidArgumentException('The amount exceeds the maximum supported value.');
        }

        return ((int) $wholeUnits * 100) + (int) str_pad($fractionalUnits, 2, '0');
    }

    /**
     * Convert an aggregate returned by the database (a DECIMAL string on
     * MySQL, or possibly a float on SQLite) into integer cents.
     */
    public static function sumToCents(mixed $databaseSum): int
    {
        if ($databaseSum === null || $databaseSum === '') {
            return 0;
        }

        $databaseSum = is_string($databaseSum) && preg_match('/^-?\d+(?:\.\d{1,2})?$/D', trim($databaseSum))
            ? trim($databaseSum)
            : number_format((float) $databaseSum, 2, '.', '');

        $isNegative = str_starts_with($databaseSum, '-');
        $amountInCents = self::toCents(ltrim($databaseSum, '-'));

        return $isNegative ? -$amountInCents : $amountInCents;
    }

    public static function fromCents(int $amountInCents): string
    {
        $sign = $amountInCents < 0 ? '-' : '';
        $absoluteAmountInCents = abs($amountInCents);

        return $sign.intdiv($absoluteAmountInCents, 100).'.'.str_pad((string) ($absoluteAmountInCents % 100), 2, '0', STR_PAD_LEFT);
    }
}
