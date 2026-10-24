<?php

namespace Database\Factories;

use App\Models\RuleSet;
use App\Models\Store;
use App\Support\AssociationRuleThresholds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A completed, validated store rule set. Use `active()` for the store's
 * current model; the scope key always follows the store.
 *
 * @extends Factory<RuleSet>
 */
class RuleSetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => RuleSet::SCOPE_STORE,
            'store_id' => Store::factory(),
            'scope_key' => fn (array $attributes): string => RuleSet::storeScopeKey($attributes['store_id']),
            'active_scope_key' => null,
            'source_type' => RuleSet::SOURCE_INTERNAL_PURCHASES,
            'source_identifier' => null,
            'source_cutoff_purchase_id' => null,
            'algorithm' => RuleSet::ALGORITHM,
            'algorithm_version' => RuleSet::ALGORITHM_VERSION,
            'parameters' => AssociationRuleThresholds::fromConfig()->toArray(),
            'status' => RuleSet::STATUS_VALIDATED,
            'eligible_basket_count' => 100,
            'distinct_item_count' => 10,
            'candidate_pair_count' => 0,
            'rule_count' => 0,
            'started_at' => now(),
            'completed_at' => now(),
            'duration_ms' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RuleSet::STATUS_ACTIVE,
            'active_scope_key' => fn (array $attributes): string => $attributes['scope_key'],
            'activated_at' => now(),
        ]);
    }
}
