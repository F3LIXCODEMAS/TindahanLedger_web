<?php

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RuleSet;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Record each list of products as one cash-sale basket in the store.
 *
 * @param  list<list<Product>>  $baskets
 */
function recordBaskets(Store $store, array $baskets): void
{
    foreach ($baskets as $products) {
        $purchase = Purchase::factory()->cash()->create(['store_id' => $store->id]);

        foreach ($products as $product) {
            PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $product->id]);
        }
    }
}

/**
 * Products A, B, and C in the store, with the four-basket fixture
 * {A,B}, {A,B}, {A,C}, {B,C} recorded.
 *
 * @return array{0: Product, 1: Product, 2: Product}
 */
function recordFourBasketFixture(Store $store): array
{
    [$a, $b, $c] = Product::factory()->count(3)->for($store)->create()->all();
    recordBaskets($store, [[$a, $b], [$a, $b], [$a, $c], [$b, $c]]);

    return [$a, $b, $c];
}

/**
 * An active rule set for the store holding one rule per [antecedent,
 * consequent, attribute overrides] entry.
 *
 * @param  list<array{0: Product, 1: Product, 2?: array<string, mixed>}>  $rules
 */
function publishRules(Store $store, array $rules): RuleSet
{
    $ruleSet = RuleSet::factory()->active()->create(['store_id' => $store->id]);

    foreach ($rules as $rule) {
        AssociationRule::factory()->create([
            'rule_set_id' => $ruleSet->id,
            'antecedent_product_id' => $rule[0]->id,
            'consequent_product_id' => $rule[1]->id,
            ...($rule[2] ?? []),
        ]);
    }

    return $ruleSet;
}
