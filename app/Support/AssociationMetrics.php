<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact support, confidence, and lift for a single-item rule A → B.
 *
 * - support    = count(A,B) / N
 * - confidence = count(A,B) / count(A)
 * - lift       = count(A,B) × N / (count(A) × count(B))
 *
 * Everything is integer arithmetic, so results never depend on floating
 * point. Stored values are rounded half-up to six decimal places.
 * Thresholds are compared exactly against the unrounded ratio, so a rule
 * exactly at a threshold is always kept (comparisons are inclusive).
 *
 * Thresholds are given in millionths (0.30 = 300000). Every product stays
 * within 64-bit integers while N is at most MAX_BASKETS.
 */
class AssociationMetrics
{
    public const SCALE = 6;

    public const UNITS = 1_000_000;

    /** Largest basket count a training run accepts. */
    public const MAX_BASKETS = 1_000_000;

    public static function support(int $pairCount, int $basketCount): string
    {
        return self::ratio($pairCount, $basketCount);
    }

    public static function confidence(int $pairCount, int $antecedentCount): string
    {
        return self::ratio($pairCount, $antecedentCount);
    }

    public static function lift(int $pairCount, int $basketCount, int $antecedentCount, int $consequentCount): string
    {
        self::guard($pairCount, $basketCount, $antecedentCount, $consequentCount);

        return self::ratio($pairCount * $basketCount, $antecedentCount * $consequentCount);
    }

    /**
     * True when numerator / denominator ≥ threshold (given in millionths).
     */
    public static function ratioMeets(int $numerator, int $denominator, int $thresholdUnits): bool
    {
        self::guardRatio($numerator, $denominator);

        return $numerator * self::UNITS >= $thresholdUnits * $denominator;
    }

    /**
     * True when lift ≥ threshold (given in millionths). For an integer
     * threshold T, x / y ≥ T exactly when floor(x / y) ≥ T, which keeps the
     * multiplication within 64 bits.
     */
    public static function liftMeets(int $pairCount, int $basketCount, int $antecedentCount, int $consequentCount, int $thresholdUnits): bool
    {
        self::guard($pairCount, $basketCount, $antecedentCount, $consequentCount);

        return intdiv($pairCount * $basketCount * self::UNITS, $antecedentCount * $consequentCount) >= $thresholdUnits;
    }

    /**
     * numerator / denominator rounded half-up to six decimals, as a string.
     */
    public static function ratio(int $numerator, int $denominator): string
    {
        self::guardRatio($numerator, $denominator);

        return self::formatUnits(intdiv(2 * $numerator * self::UNITS + $denominator, 2 * $denominator));
    }

    public static function formatUnits(int $units): string
    {
        return sprintf('%d.%06d', intdiv($units, self::UNITS), $units % self::UNITS);
    }

    private static function guardRatio(int $numerator, int $denominator): void
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new InvalidArgumentException('A ratio needs a positive denominator and a non-negative numerator.');
        }
    }

    private static function guard(int $pairCount, int $basketCount, int $antecedentCount, int $consequentCount): void
    {
        if ($basketCount > self::MAX_BASKETS) {
            throw new InvalidArgumentException('Basket counts above '.self::MAX_BASKETS.' are not supported.');
        }

        if ($pairCount < 0 || $pairCount > min($antecedentCount, $consequentCount) || max($antecedentCount, $consequentCount) > $basketCount) {
            throw new InvalidArgumentException('Counts are inconsistent: a pair cannot be more frequent than either product, nor a product than the baskets.');
        }

        self::guardRatio($pairCount, $antecedentCount * $consequentCount);
    }
}
