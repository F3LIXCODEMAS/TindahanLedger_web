<?php

namespace App\Support;

use App\Exceptions\InvalidAssociationThresholds;

/**
 * Validated training thresholds. Probabilities and lift are held in
 * millionths so they can be compared exactly (see AssociationMetrics).
 * All comparisons are inclusive (≥).
 */
final class AssociationRuleThresholds
{
    public const MAX_LIFT_UNITS = 1000 * AssociationMetrics::UNITS;

    public const MAX_COUNT = AssociationMetrics::MAX_BASKETS;

    public function __construct(
        public readonly int $minSupportUnits,
        public readonly int $minConfidenceUnits,
        public readonly ?int $minLiftUnits,
        public readonly int $minBaskets,
        public readonly int $minAntecedentCount,
        public readonly int $minPairCount,
    ) {}

    /**
     * Read the configured defaults, replacing any value given in
     * `$overrides` (keys as in config/association_rules.php).
     *
     * @param  array<string, string|int|null>  $overrides
     *
     * @throws InvalidAssociationThresholds
     */
    public static function fromConfig(array $overrides = []): self
    {
        $values = array_merge(config('association_rules'), array_filter($overrides, fn ($value): bool => $value !== null));
        $minLift = strtolower(trim((string) ($values['min_lift'] ?? '')));

        return new self(
            minSupportUnits: self::probability('min_support', $values['min_support'] ?? null),
            minConfidenceUnits: self::probability('min_confidence', $values['min_confidence'] ?? null),
            minLiftUnits: in_array($minLift, ['', 'off', 'none', 'null'], true) ? null : self::decimal('min_lift', $minLift, self::MAX_LIFT_UNITS),
            minBaskets: self::count('min_baskets', $values['min_baskets'] ?? null),
            minAntecedentCount: self::count('min_antecedent_count', $values['min_antecedent_count'] ?? null),
            minPairCount: self::count('min_pair_count', $values['min_pair_count'] ?? null),
        );
    }

    /**
     * @return array{min_support: string, min_confidence: string, min_lift: string|null, min_baskets: int, min_antecedent_count: int, min_pair_count: int, comparisons: string}
     */
    public function toArray(): array
    {
        return [
            'min_support' => AssociationMetrics::formatUnits($this->minSupportUnits),
            'min_confidence' => AssociationMetrics::formatUnits($this->minConfidenceUnits),
            'min_lift' => $this->minLiftUnits === null ? null : AssociationMetrics::formatUnits($this->minLiftUnits),
            'min_baskets' => $this->minBaskets,
            'min_antecedent_count' => $this->minAntecedentCount,
            'min_pair_count' => $this->minPairCount,
            'comparisons' => 'inclusive',
        ];
    }

    private static function probability(string $name, mixed $value): int
    {
        return self::decimal($name, $value, AssociationMetrics::UNITS);
    }

    /**
     * A non-negative decimal with at most six decimal places, up to `$maxUnits`.
     */
    private static function decimal(string $name, mixed $value, int $maxUnits): int
    {
        $text = trim((string) $value);

        if (! preg_match('/^(\d{1,7})(?:\.(\d{1,6}))?$/', $text, $matches)) {
            throw new InvalidAssociationThresholds("{$name} must be a decimal number with at most six decimal places, such as 0.30.");
        }

        $units = (int) $matches[1] * AssociationMetrics::UNITS + (int) str_pad($matches[2] ?? '', 6, '0');

        if ($units > $maxUnits) {
            throw new InvalidAssociationThresholds("{$name} must be between 0 and ".AssociationMetrics::formatUnits($maxUnits).'.');
        }

        return $units;
    }

    private static function count(string $name, mixed $value): int
    {
        $text = trim((string) $value);

        if (! preg_match('/^\d{1,7}$/', $text) || (int) $text < 1 || (int) $text > self::MAX_COUNT) {
            throw new InvalidAssociationThresholds("{$name} must be a whole number from 1 to ".self::MAX_COUNT.'.');
        }

        return (int) $text;
    }
}
