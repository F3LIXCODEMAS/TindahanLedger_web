<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Purchase quantities: greater than zero, at most 1,000, and at most three
 * decimal places (for example 0.25 kg). Values are handled as whole
 * thousandths so no floating-point arithmetic is involved. Invalid values
 * are rejected, never rounded.
 */
final class Quantity
{
    public const SCALE = 3;

    public const MAXIMUM = 1000;

    public const FORMAT_MESSAGE = 'Enter a quantity greater than zero with up to 3 decimal places.';

    public const MAXIMUM_MESSAGE = 'The quantity may not exceed 1,000.';

    /**
     * Digits allowed before and after the decimal point. The maximum is
     * checked separately so values such as 1500 get the clearer message.
     */
    public const PATTERN = '/^\d{1,4}(?:\.\d{1,3})?$/D';

    private const THOUSANDTHS_PER_UNIT = 1000;

    public static function toThousandths(string|int $quantity): int
    {
        $quantity = trim((string) $quantity);

        if (! preg_match(self::PATTERN, $quantity)) {
            throw new InvalidArgumentException(self::FORMAT_MESSAGE);
        }

        [$wholeUnits, $fractionalUnits] = array_pad(explode('.', $quantity, 2), 2, '');
        $thousandths = ((int) $wholeUnits * self::THOUSANDTHS_PER_UNIT) + (int) str_pad($fractionalUnits, self::SCALE, '0');

        if ($thousandths === 0) {
            throw new InvalidArgumentException(self::FORMAT_MESSAGE);
        }

        if ($thousandths > self::MAXIMUM * self::THOUSANDTHS_PER_UNIT) {
            throw new InvalidArgumentException(self::MAXIMUM_MESSAGE);
        }

        return $thousandths;
    }

    /**
     * Fixed three-decimal form used for storage, such as "0.250".
     */
    public static function fromThousandths(int $thousandths): string
    {
        return intdiv($thousandths, self::THOUSANDTHS_PER_UNIT).'.'.str_pad((string) ($thousandths % self::THOUSANDTHS_PER_UNIT), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Display form without trailing zeros: "0.250" becomes "0.25" and
     * "2.000" becomes "2".
     */
    public static function forDisplay(string $storedQuantity): string
    {
        return str_contains($storedQuantity, '.')
            ? rtrim(rtrim($storedQuantity, '0'), '.')
            : $storedQuantity;
    }
}
