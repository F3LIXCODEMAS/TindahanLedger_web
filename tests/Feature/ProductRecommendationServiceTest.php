<?php

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use App\Services\ProductRecommendationService;

function recommendations(): ProductRecommendationService
{
    return app(ProductRecommendationService::class);
}

/**
 * An active rule set for the store with the given rules from `$antecedent`.
 *
 * @param  list<array<string, mixed>>  $rules  attribute overrides per rule
 */
function activeRules(Store $store, Product $antecedent, array $rules): RuleSet
{
    $ruleSet = RuleSet::factory()->active()->create(['store_id' => $store->id]);

    foreach ($rules as $attributes) {
        AssociationRule::factory()->create(['rule_set_id' => $ruleSet->id, 'antecedent_product_id' => $antecedent->id, ...$attributes]);
    }

    return $ruleSet;
}

it('returns the active rules for a product ordered by confidence, lift, and co-occurrence count', function () {
    $store = Store::factory()->create();
    [$noodles, $softDrink, $egg, $bread] = Product::factory()->count(4)->for($store)->create()->all();
    activeRules($store, $noodles, [
        ['consequent_product_id' => $egg->id, 'confidence' => '0.400000', 'lift' => '3.000000'],
        ['consequent_product_id' => $softDrink->id, 'confidence' => '0.600000', 'lift' => '1.500000'],
        ['consequent_product_id' => $bread->id, 'confidence' => '0.400000', 'lift' => '3.000000', 'pair_count' => 12],
    ]);

    $results = recommendations()->forProduct($store, $noodles);

    expect($results->pluck('consequent_product_id')->all())->toBe([$softDrink->id, $bread->id, $egg->id]);
    expect($results->first()->consequentProduct->is($softDrink))->toBeTrue();
    expect($results->first()->only(['antecedent_count', 'pair_count', 'confidence', 'lift']))->toBe(['antecedent_count' => 20, 'pair_count' => 10, 'confidence' => '0.600000', 'lift' => '1.500000']);
});

it('reads only the active version', function () {
    $store = Store::factory()->create();
    [$noodles, $softDrink, $egg] = Product::factory()->count(3)->for($store)->create()->all();
    $superseded = RuleSet::factory()->create(['store_id' => $store->id, 'status' => RuleSet::STATUS_SUPERSEDED]);
    AssociationRule::factory()->create(['rule_set_id' => $superseded->id, 'antecedent_product_id' => $noodles->id, 'consequent_product_id' => $egg->id]);
    activeRules($store, $noodles, [['consequent_product_id' => $softDrink->id]]);

    expect(recommendations()->forProduct($store, $noodles)->pluck('consequent_product_id')->all())->toBe([$softDrink->id]);
});

it('returns nothing when the store has no active rule set', function () {
    $store = Store::factory()->create();
    [$noodles, $softDrink] = Product::factory()->count(2)->for($store)->create()->all();
    $validated = RuleSet::factory()->create(['store_id' => $store->id]);
    AssociationRule::factory()->create(['rule_set_id' => $validated->id, 'antecedent_product_id' => $noodles->id, 'consequent_product_id' => $softDrink->id]);

    expect(recommendations()->forProduct($store, $noodles))->toBeEmpty();
});

it('skips recommended products that are inactive in the catalog', function () {
    $store = Store::factory()->create();
    $noodles = Product::factory()->for($store)->create();
    $discontinued = Product::factory()->for($store)->inactive()->create();
    activeRules($store, $noodles, [['consequent_product_id' => $discontinued->id]]);

    expect(recommendations()->forProduct($store, $noodles))->toBeEmpty();
});

it('never returns another store\'s rules or products', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    [$noodlesB, $softDrinkB] = Product::factory()->count(2)->for($storeB)->create()->all();
    activeRules($storeB, $noodlesB, [['consequent_product_id' => $softDrinkB->id]]);
    $noodlesA = Product::factory()->for($storeA)->create();

    expect(recommendations()->forProduct($storeA, $noodlesB))->toBeEmpty();
    expect(recommendations()->forProduct($storeA, $noodlesA))->toBeEmpty();
    expect(recommendations()->forProduct($storeB, $noodlesB))->toHaveCount(1);
});

it('never returns external experiment rules', function () {
    $store = Store::factory()->create();
    $noodles = Product::factory()->for($store)->create();
    $experiment = RuleSet::factory()->create([
        'scope_type' => RuleSet::SCOPE_EXTERNAL_EXPERIMENT,
        'store_id' => null,
        'scope_key' => RuleSet::externalScopeKey(str_repeat('b', 64)),
    ]);
    AssociationRule::query()->insert([
        'rule_set_id' => $experiment->id, 'antecedent_label' => $noodles->normalized_name, 'consequent_label' => 'soft drink',
        'antecedent_count' => 5, 'consequent_count' => 5, 'pair_count' => 5, 'support' => '0.5', 'confidence' => '1', 'lift' => '2', 'created_at' => now(),
    ]);

    expect(recommendations()->forProduct($store, $noodles))->toBeEmpty();
});

it('limits the number of recommendations', function () {
    $store = Store::factory()->create();
    $noodles = Product::factory()->for($store)->create();
    activeRules($store, $noodles, Product::factory()->count(3)->for($store)->create()->map(fn (Product $product): array => ['consequent_product_id' => $product->id])->all());

    expect(recommendations()->forProduct($store, $noodles, limit: 2))->toHaveCount(2);
    expect(fn () => recommendations()->forProduct($store, $noodles, limit: 0))->toThrow(InvalidArgumentException::class);
});
