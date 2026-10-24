<?php

namespace Database\Factories;

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A store rule: the store follows the rule set and both products are
 * created in that store, so the same-store foreign keys hold. The default
 * counts (N = 100, A = 20, B = 25, A and B = 10) give support 0.10,
 * confidence 0.50, and lift 2.00.
 *
 * @extends Factory<AssociationRule>
 */
class AssociationRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_set_id' => RuleSet::factory(),
            'store_id' => fn (array $attributes): int => RuleSet::query()->findOrFail($attributes['rule_set_id'])->store_id,
            'antecedent_product_id' => fn (array $attributes) => Product::factory()->state(['store_id' => $attributes['store_id']]),
            'consequent_product_id' => fn (array $attributes) => Product::factory()->state(['store_id' => $attributes['store_id']]),
            'antecedent_label' => null,
            'consequent_label' => null,
            'antecedent_count' => 20,
            'consequent_count' => 25,
            'pair_count' => 10,
            'support' => '0.100000',
            'confidence' => '0.500000',
            'lift' => '2.000000',
        ];
    }
}
