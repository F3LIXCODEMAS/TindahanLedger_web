<?php

namespace App\Support;

use App\Models\RuleSet;

/**
 * The result of one training request. `ruleSet` is null for dry runs and
 * for runs that could not start (lock held, unreadable dataset).
 */
final class TrainingOutcome
{
    public const LOCKED = 'locked';

    public const DRY_RUN = 'dry_run';

    /**
     * @param  string  $status  a RuleSet status, LOCKED, or DRY_RUN
     * @param  list<array{antecedent: string, consequent: string, antecedent_count: int, consequent_count: int, pair_count: int, support: string, confidence: string, lift: string}>  $previewRules  dry runs only
     */
    public function __construct(
        public readonly string $scopeKey,
        public readonly string $status,
        public readonly ?RuleSet $ruleSet = null,
        public readonly ?int $basketCount = null,
        public readonly ?int $candidatePairCount = null,
        public readonly ?int $ruleCount = null,
        public readonly ?string $reason = null,
        public readonly ?int $durationMs = null,
        public readonly array $previewRules = [],
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->status, [
            RuleSet::STATUS_ACTIVE,
            RuleSet::STATUS_VALIDATED,
            RuleSet::STATUS_INSUFFICIENT_DATA,
            self::DRY_RUN,
        ], true);
    }
}
