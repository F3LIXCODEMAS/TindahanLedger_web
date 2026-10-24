<?php

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use App\Services\ProductRecommendationService;
use Illuminate\Support\Facades\DB;

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

describe('basket recommendations', function () {
    it('judges each suggestion by its strongest rule from any basket product', function () {
        $store = Store::factory()->create();
        [$noodles, $bread, $egg, $softDrink] = Product::factory()->count(4)->for($store)->create()->all();
        publishRules($store, [
            [$noodles, $egg, ['confidence' => '0.400000']],
            [$bread, $egg, ['confidence' => '0.700000']],
            [$noodles, $softDrink, ['confidence' => '0.600000']],
        ]);

        $results = recommendations()->forBasket($store, [$noodles->id, $bread->id]);

        expect($results->pluck('consequent_product_id')->all())->toBe([$egg->id, $softDrink->id]);
        expect($results->first()->antecedentProduct->is($bread))->toBeTrue();
        expect($results->first()->confidence)->toBe('0.700000');
    });

    it('breaks ties by lift, then co-occurrence count, then the lower product ID', function () {
        $store = Store::factory()->create();
        $noodles = Product::factory()->for($store)->create();
        [$first, $second, $third, $fourth] = Product::factory()->count(4)->for($store)->create()->all();
        publishRules($store, [
            [$noodles, $fourth, ['confidence' => '0.500000', 'lift' => '2.000000', 'pair_count' => 10]],
            [$noodles, $third, ['confidence' => '0.500000', 'lift' => '2.000000', 'pair_count' => 10]],
            [$noodles, $second, ['confidence' => '0.500000', 'lift' => '2.000000', 'pair_count' => 12]],
            [$noodles, $first, ['confidence' => '0.500000', 'lift' => '3.000000', 'pair_count' => 10]],
        ]);

        $results = recommendations()->forBasket($store, [$noodles->id], limit: 4);

        expect($results->pluck('consequent_product_id')->all())->toBe([$first->id, $second->id, $third->id, $fourth->id]);
    });

    it('explains an equally strong suggestion with the lower antecedent ID', function () {
        $store = Store::factory()->create();
        [$noodles, $bread, $egg] = Product::factory()->count(3)->for($store)->create()->all();
        publishRules($store, [[$bread, $egg], [$noodles, $egg]]);

        expect(recommendations()->forBasket($store, [$bread->id, $noodles->id])->sole()->antecedent_product_id)->toBe($noodles->id);
    });

    it('excludes basket products and ignores other stores\' and inactive basket products', function () {
        $store = Store::factory()->create();
        [$noodles, $softDrink, $egg] = Product::factory()->count(3)->for($store)->create()->all();
        $inactive = Product::factory()->for($store)->inactive()->create();
        $foreign = Product::factory()->create();
        publishRules($store, [[$noodles, $softDrink], [$inactive, $egg]]);
        publishRules($foreign->store, [[$foreign, Product::factory()->for($foreign->store)->create()]]);

        expect(recommendations()->forBasket($store, [$noodles->id, $softDrink->id, $inactive->id, $foreign->id]))->toBeEmpty();
        expect(recommendations()->forBasket($store, [$noodles->id, $foreign->id])->pluck('consequent_product_id')->all())->toBe([$softDrink->id]);
    });

    it('returns nothing without an active model or with an empty basket', function () {
        $store = Store::factory()->create();
        [$noodles, $softDrink] = Product::factory()->count(2)->for($store)->create()->all();

        expect(recommendations()->forBasket($store, [$noodles->id]))->toBeEmpty();
        expect(recommendations()->hasActiveModel($store))->toBeFalse();

        publishRules($store, [[$noodles, $softDrink]]);

        expect(recommendations()->hasActiveModel($store))->toBeTrue();
        expect(recommendations()->forBasket($store, []))->toBeEmpty();
    });

    it('defaults to three suggestions and rejects invalid limits and oversized baskets', function () {
        $store = Store::factory()->create();
        $noodles = Product::factory()->for($store)->create();
        publishRules($store, Product::factory()->count(4)->for($store)->create()->map(fn (Product $product): array => [$noodles, $product])->all());

        expect(recommendations()->forBasket($store, [$noodles->id]))->toHaveCount(ProductRecommendationService::BASKET_LIMIT);
        expect(fn () => recommendations()->forBasket($store, [$noodles->id], limit: 0))->toThrow(InvalidArgumentException::class);
        expect(fn () => recommendations()->forBasket($store, range(1, ProductRecommendationService::MAX_BASKET_PRODUCTS + 1)))->toThrow(InvalidArgumentException::class);
    });

    it('uses a fixed number of queries however many suggestions it returns', function () {
        $store = Store::factory()->create();
        $basket = Product::factory()->count(5)->for($store)->create();
        $candidates = Product::factory()->count(6)->for($store)->create();
        publishRules($store, $basket->crossJoin($candidates)->map(fn (array $pair): array => [$pair[0], $pair[1]])->all());
        DB::enableQueryLog();

        $results = recommendations()->forBasket($store, $basket->pluck('id')->all());
        $results->each(fn (AssociationRule $rule): string => $rule->antecedentProduct->name.$rule->consequentProduct->name);

        expect($results)->toHaveCount(3);
        expect(DB::getQueryLog())->toHaveCount(5);
    });
});
