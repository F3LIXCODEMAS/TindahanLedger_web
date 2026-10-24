<?php

use App\Models\AssociationRule;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\RuleSet;
use App\Models\Store;
use App\Models\User;
use App\Services\AssociationRuleTrainer;
use App\Services\ProductRecommendationService;
use App\Support\AssociationRuleThresholds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  list<int|string>  $productIds
 * @param  array<string, mixed>  $extra
 */
function recommendationsUrl(array $productIds, array $extra = []): string
{
    return route('credits.product-recommendations', ['product_ids' => $productIds, ...$extra]);
}

describe('access', function () {
    it('redirects guests and rejects unauthenticated JSON requests', function () {
        $this->get(recommendationsUrl([1]))->assertRedirect(route('login'));
        $this->getJson(recommendationsUrl([1]))->assertUnauthorized();
    });

    it('is only for store owners', function () {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(recommendationsUrl([1]))
            ->assertForbidden();
    });
});

describe('recommendations', function () {
    it('returns suggestions from the owner\'s active rule set with a short explanation', function () {
        $store = Store::factory()->create();
        $noodles = Product::factory()->for($store)->create(['name' => 'Pancit Canton', 'unit_price' => '15.00']);
        $softDrink = Product::factory()->for($store)->create(['name' => 'Royal 295ml', 'unit_price' => '20.00']);
        publishRules($store, [[$noodles, $softDrink]]);

        $this->actingAs($store->owner)
            ->getJson(recommendationsUrl([$noodles->id]))
            ->assertOk()
            ->assertExactJson([
                'status' => 'available',
                'recommendations' => [
                    ['id' => $softDrink->id, 'name' => 'Royal 295ml', 'unit_price' => '20.00', 'explanation' => 'Frequently bought with Pancit Canton.'],
                ],
            ]);
    });

    it('works end to end with a model trained on the store\'s purchases', function () {
        $store = Store::factory()->create();
        [$a, $b] = recordFourBasketFixture($store);
        app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig([
            'min_support' => '0', 'min_confidence' => '0', 'min_lift' => 'off', 'min_baskets' => '1', 'min_antecedent_count' => '1', 'min_pair_count' => '1',
        ]));

        $response = $this->actingAs($store->owner)->getJson(recommendationsUrl([$a->id]))->assertOk();

        // From A: A → B has confidence 2/3 and A → C 1/3.
        expect($response->json('status'))->toBe('available');
        expect(array_column($response->json('recommendations'), 'id'))->toBe([$b->id, Product::query()->whereNotIn('id', [$a->id, $b->id])->value('id')]);
    });

    it('never returns more than three suggestions', function () {
        $store = Store::factory()->create();
        $noodles = Product::factory()->for($store)->create();
        publishRules($store, Product::factory()->count(5)->for($store)->create()->map(fn (Product $product): array => [$noodles, $product])->all());

        $response = $this->actingAs($store->owner)->getJson(recommendationsUrl([$noodles->id]))->assertOk();

        expect($response->json('recommendations'))->toHaveCount(3);
    });

    it('excludes products already in the basket', function () {
        $store = Store::factory()->create();
        [$noodles, $softDrink, $egg] = Product::factory()->count(3)->for($store)->create()->all();
        publishRules($store, [[$noodles, $softDrink, ['confidence' => '0.900000']], [$noodles, $egg]]);

        $response = $this->actingAs($store->owner)->getJson(recommendationsUrl([$noodles->id, $softDrink->id]))->assertOk();

        expect(array_column($response->json('recommendations'), 'id'))->toBe([$egg->id]);
    });

    it('says when nothing in the basket has a suggestion', function () {
        $store = Store::factory()->create();
        [$noodles, $softDrink, $soap] = Product::factory()->count(3)->for($store)->create()->all();
        publishRules($store, [[$noodles, $softDrink]]);

        $this->actingAs($store->owner)
            ->getJson(recommendationsUrl([$soap->id]))
            ->assertOk()
            ->assertExactJson(['status' => 'no_matches', 'recommendations' => []]);
    });

    it('returns an empty basket status without reading any rules', function () {
        $store = Store::factory()->create();
        publishRules($store, [[Product::factory()->for($store)->create(), Product::factory()->for($store)->create()]]);

        $this->actingAs($store->owner)->getJson(route('credits.product-recommendations'))
            ->assertOk()
            ->assertExactJson(['status' => 'empty_basket', 'recommendations' => []]);
    });
});

describe('model availability', function () {
    it('reports no active model when the store has only unpublished rule sets', function (string $status) {
        $store = Store::factory()->create();
        [$noodles, $softDrink] = Product::factory()->count(2)->for($store)->create()->all();
        $ruleSet = RuleSet::factory()->create(['store_id' => $store->id, 'status' => $status]);
        AssociationRule::factory()->create(['rule_set_id' => $ruleSet->id, 'antecedent_product_id' => $noodles->id, 'consequent_product_id' => $softDrink->id]);

        $this->actingAs($store->owner)
            ->getJson(recommendationsUrl([$noodles->id]))
            ->assertOk()
            ->assertExactJson(['status' => 'no_active_model', 'recommendations' => []]);
    })->with([RuleSet::STATUS_VALIDATED, RuleSet::STATUS_SUPERSEDED, RuleSet::STATUS_FAILED]);

    it('gives no suggestions when the store has too little purchase history', function () {
        $store = Store::factory()->create();
        [$a] = recordFourBasketFixture($store);
        $outcome = app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig());

        expect($outcome->status)->toBe(RuleSet::STATUS_INSUFFICIENT_DATA);
        $this->actingAs($store->owner)
            ->getJson(recommendationsUrl([$a->id]))
            ->assertOk()
            ->assertExactJson(['status' => 'no_active_model', 'recommendations' => []]);
    });
});

describe('store isolation and input handling', function () {
    it('never uses another store\'s rules or products, whatever the request says', function () {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        [$noodlesB, $softDrinkB] = Product::factory()->count(2)->for($storeB)->create()->all();
        publishRules($storeB, [[$noodlesB, $softDrinkB]]);
        $noodlesA = Product::factory()->for($storeA)->create();
        publishRules($storeA, [[$noodlesA, Product::factory()->for($storeA)->create()]]);

        $this->actingAs($storeA->owner)
            ->getJson(recommendationsUrl([$noodlesB->id], ['store_id' => $storeB->id]))
            ->assertOk()
            ->assertExactJson(['status' => 'no_matches', 'recommendations' => []]);
    });

    it('ignores client-supplied metrics, limits, and scores', function () {
        $store = Store::factory()->create();
        [$noodles, $softDrink, $egg] = Product::factory()->count(3)->for($store)->create()->all();
        publishRules($store, [[$noodles, $softDrink, ['confidence' => '0.900000']], [$noodles, $egg, ['confidence' => '0.400000']]]);
        $expected = $this->actingAs($store->owner)->getJson(recommendationsUrl([$noodles->id]))->json();

        $tampered = $this->actingAs($store->owner)->getJson(recommendationsUrl([$noodles->id], [
            'confidence' => [$egg->id => 1],
            'lift' => 99,
            'limit' => 50,
            'rank' => [$egg->id, $softDrink->id],
            'rule_set_id' => 999,
        ]))->json();

        expect($tampered)->toBe($expected);
        expect(array_column($tampered['recommendations'], 'id'))->toBe([$softDrink->id, $egg->id]);
    });

    it('ignores inactive, deleted, and unknown products in the basket', function () {
        $store = Store::factory()->create();
        $discontinued = Product::factory()->for($store)->inactive()->create();
        [$noodles, $softDrink, $egg] = Product::factory()->count(3)->for($store)->create()->all();
        publishRules($store, [[$discontinued, $softDrink], [$noodles, $egg]]);

        $response = $this->actingAs($store->owner)->getJson(recommendationsUrl([$discontinued->id, 999_999, $noodles->id]))->assertOk();

        expect(array_column($response->json('recommendations'), 'id'))->toBe([$egg->id]);
    });

    it('never suggests inactive products', function () {
        $store = Store::factory()->create();
        $noodles = Product::factory()->for($store)->create();
        publishRules($store, [[$noodles, Product::factory()->for($store)->inactive()->create()]]);

        $this->actingAs($store->owner)
            ->getJson(recommendationsUrl([$noodles->id]))
            ->assertExactJson(['status' => 'no_matches', 'recommendations' => []]);
    });

    it('rejects malformed product lists', function (array $productIds, string $errorKey) {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)
            ->getJson(route('credits.product-recommendations', ['product_ids' => $productIds]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($errorKey);
    })->with([
        'not a number' => [['abc'], 'product_ids.0'],
        'zero' => [[0], 'product_ids.0'],
        'repeated' => [[5, 5], 'product_ids.1'],
        'too many' => [range(1, ProductRecommendationService::MAX_BASKET_PRODUCTS + 1), 'product_ids'],
    ]);
});

describe('transaction safety', function () {
    it('is read-only and leaves balances untouched', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['current_balance' => '150.00']);
        [$noodles, $softDrink] = Product::factory()->count(2)->for($store)->create()->all();
        publishRules($store, [[$noodles, $softDrink]]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($store->owner)->getJson(recommendationsUrl([$noodles->id]))->assertOk();

        expect(collect($writes)->reject(fn (string $sql): bool => str_contains($sql, 'sessions'))->all())->toBe([]);
        expect($customer->fresh()->current_balance)->toBe('150.00');
        expect(Purchase::query()->count())->toBe(0);
    });

    it('records transactions without depending on recommendations', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '20.00']);
        $this->mock(ProductRecommendationService::class)->shouldNotReceive('forBasket', 'hasActiveModel');

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1', 'unit_price' => '20.00']],
            'expected_total' => '20.00',
        ])->assertRedirect();

        expect(Purchase::query()->sole()->purchase_type)->toBe(Purchase::TYPE_CASH);
    });
});

describe('transaction entry pages', function () {
    it('shows the suggestions panel in both product pickers', function (string $routeName) {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create();

        $this->actingAs($store->owner)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee('data-credit-recommendations', false)
            ->assertSee('data-recommendations-url="'.route('credits.product-recommendations').'"', false)
            ->assertSee('Often bought together');
    })->with(['credits.create', 'cash-purchases.create']);
});
