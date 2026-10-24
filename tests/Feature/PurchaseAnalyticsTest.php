<?php

use App\Models\AssociationRule;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RuleSet;
use App\Models\Store;
use App\Models\User;
use App\Services\AssociationRuleDiagnosticsService;
use App\Services\AssociationRuleTrainer;
use App\Services\PurchaseBasketCounter;
use App\Services\PurchasePatternAnalyticsService;
use App\Services\RecommendationModelStatusService;
use App\Support\AssociationRuleThresholds;
use Illuminate\Support\Facades\DB;

/**
 * One purchase in the store on `$date` with [product, quantity] lines;
 * utang purchases get their own customer and ledger entry.
 *
 * @param  list<array{0: Product, 1: string}>  $lines
 */
function analyticsBasket(Store $store, array $lines, string $date, bool $utang = false): Purchase
{
    if ($utang) {
        $customer = Customer::factory()->for($store)->create(['full_name' => 'Maria Santos']);
        $credit = LedgerEntry::factory()->for($customer)->create(['transaction_date' => $date]);
        $purchase = Purchase::factory()->create(['ledger_entry_id' => $credit->id, 'purchase_date' => $date]);
    } else {
        $purchase = Purchase::factory()->cash()->create(['store_id' => $store->id, 'purchase_date' => $date]);
    }

    foreach ($lines as [$product, $quantity]) {
        PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    return $purchase;
}

/**
 * October: {A×2, B×1} cash, {A, B×3, C} cash, {A, C×2} utang, {B} cash.
 * September: {A×5, B×5}. Plus two ineligible October purchases.
 *
 * @return array{0: Store, 1: Product, 2: Product, 3: Product}
 */
function octoberFixture(): array
{
    $store = Store::factory()->create();
    $a = Product::factory()->for($store)->create(['name' => 'Pancit Canton']);
    $b = Product::factory()->for($store)->create(['name' => 'Ligo Sardines']);
    $c = Product::factory()->for($store)->create(['name' => 'Pandesal']);

    analyticsBasket($store, [[$a, '2'], [$b, '1']], '2026-10-02');
    analyticsBasket($store, [[$a, '1'], [$b, '3'], [$c, '1']], '2026-10-05');
    analyticsBasket($store, [[$a, '1'], [$c, '2']], '2026-10-10', utang: true);
    analyticsBasket($store, [[$b, '1']], '2026-10-31');
    analyticsBasket($store, [[$a, '5'], [$b, '5']], '2026-09-15');
    analyticsBasket($store, [[$c, '0']], '2026-10-12');
    analyticsBasket($store, [[$a, '4'], [$c, '4']], '2026-10-13')->forceFill(['purchase_type' => 'draft'])->save();

    return [$store, $a, $b, $c];
}

/**
 * Baskets {A,B}, {A,B}, {A}, {C}: A → B has confidence 2/3 and B → A has 1,
 * both with lift 4/3.
 *
 * @return array{0: Product, 1: Product, 2: Product}
 */
function positiveLiftFixture(Store $store): array
{
    [$a, $b, $c] = Product::factory()->count(3)->for($store)->create()->all();
    recordBaskets($store, [[$a, $b], [$a, $b], [$a], [$c]]);

    return [$a, $b, $c];
}

function analytics(): PurchasePatternAnalyticsService
{
    return app(PurchasePatternAnalyticsService::class);
}

describe('purchase analytics', function () {
    it('counts eligible baskets, products, quantities, shares, and types for the period', function () {
        [$store, $a, $b, $c] = octoberFixture();

        $summary = analytics()->summary($store, '2026-10-01', '2026-10-31');

        expect($summary)->toMatchArray(['basket_count' => 4, 'distinct_products' => 3, 'baskets_by_type' => ['cash' => 3, 'utang' => 1]]);
        expect($summary['top_products'])->toBe([
            ['product_id' => $b->id, 'name' => 'Ligo Sardines', 'basket_count' => 3, 'total_quantity' => '5', 'basket_share' => '0.750000'],
            ['product_id' => $a->id, 'name' => 'Pancit Canton', 'basket_count' => 3, 'total_quantity' => '4', 'basket_share' => '0.750000'],
            ['product_id' => $c->id, 'name' => 'Pandesal', 'basket_count' => 2, 'total_quantity' => '3', 'basket_share' => '0.500000'],
        ]);
    });

    it('lists pairs seen in at least the minimum co-occurrence count, with support and active-model rules', function () {
        config(['association_rules.min_pair_count' => '2']);
        [$store, $a, $b, $c] = octoberFixture();
        publishRules($store, [[$a, $b, ['confidence' => '0.666667', 'lift' => '0.888889']]]);

        $pairs = analytics()->summary($store, '2026-10-01', '2026-10-31')['top_pairs'];

        expect(array_map(fn (array $pair): array => [$pair['first_name'], $pair['second_name'], $pair['pair_count'], $pair['support']], $pairs))->toBe([
            ['Pancit Canton', 'Ligo Sardines', 2, '0.500000'],
            ['Pancit Canton', 'Pandesal', 2, '0.500000'],
        ]);
        expect($pairs[0]['rules'])->toBe([['antecedent_product_id' => $a->id, 'consequent_product_id' => $b->id, 'confidence' => '0.666667', 'lift' => '0.888889']]);
        expect($pairs[1]['rules'])->toBe([]);
    });

    it('lists no pairs below the configured minimum co-occurrence count', function () {
        [$store] = octoberFixture();

        expect(analytics()->summary($store, '2026-10-01', '2026-10-31')['top_pairs'])->toBe([]);
    });

    it('applies the date range to every figure, including the end date', function () {
        [$store] = octoberFixture();

        expect(analytics()->summary($store, '2026-09-01', '2026-09-30'))->toMatchArray(['basket_count' => 1, 'distinct_products' => 2]);
        expect(analytics()->summary($store, '2026-10-31', '2026-10-31')['basket_count'])->toBe(1);
        expect(analytics()->summary($store, '2025-01-01', '2025-12-31'))->toMatchArray(['basket_count' => 0, 'top_products' => [], 'top_pairs' => []]);
    });

    it('counts exactly the baskets training counts when no period is given', function () {
        [$store] = octoberFixture();
        $counter = app(PurchaseBasketCounter::class);

        // Five eligible purchases; the zero-quantity and draft purchases are excluded by both paths.
        expect($counter->eligibleItems($store->id)->distinct()->count('purchase_items.purchase_id'))
            ->toBe($counter->count($store->id, 1)['counts']->basketCount)
            ->toBe(5);
    });

    it('reports store totals that tell the empty states apart', function () {
        $store = Store::factory()->create();
        expect(analytics()->storeTotals($store))->toBe(['purchases' => 0, 'eligible_baskets' => 0]);

        analyticsBasket($store, [[Product::factory()->for($store)->create(), '0']], '2026-10-01');

        expect(analytics()->storeTotals($store))->toBe(['purchases' => 1, 'eligible_baskets' => 0]);
    });
});

describe('model transparency', function () {
    it('reports the active model from persisted metadata and the configured thresholds', function () {
        [$store, $a, $b] = octoberFixture();
        $ruleSet = publishRules($store, [[$a, $b]]);
        $ruleSet->forceFill(['eligible_basket_count' => 123, 'completed_at' => '2026-10-20 08:30:00'])->save();

        $status = app(RecommendationModelStatusService::class)->forStore($store);

        expect($status)->toMatchArray(['status' => RuleSet::STATUS_ACTIVE, 'active_rule_count' => 1, 'scope' => RuleSet::SCOPE_STORE, 'global_fallback' => false, 'thresholds_changed_since_training' => false]);
        expect($status['active']->eligible_basket_count)->toBe(123);
        expect($status['configured_thresholds'])->toBe(AssociationRuleThresholds::fromConfig()->toArray());
    });

    it('reports the latest run when no model is active', function () {
        $store = Store::factory()->create();
        RuleSet::factory()->create(['store_id' => $store->id, 'status' => RuleSet::STATUS_INSUFFICIENT_DATA, 'eligible_basket_count' => 6]);

        $status = app(RecommendationModelStatusService::class)->forStore($store);

        expect($status)->toMatchArray(['status' => RuleSet::STATUS_INSUFFICIENT_DATA, 'active' => null, 'active_rule_count' => null]);
        expect(app(RecommendationModelStatusService::class)->forStore(Store::factory()->create())['status'])->toBe('not_trained');
    });

    it('flags thresholds that changed after training', function () {
        $store = Store::factory()->create();
        RuleSet::factory()->active()->create(['store_id' => $store->id]);
        config(['association_rules.min_confidence' => '0.5']);

        expect(app(RecommendationModelStatusService::class)->forStore($store)['thresholds_changed_since_training'])->toBeTrue();
    });
});

describe('rule diagnostics', function () {
    beforeEach(function () {
        config(['association_rules' => [...config('association_rules'), 'min_support' => '0', 'min_confidence' => '0', 'min_lift' => 'off', 'min_baskets' => '1', 'min_antecedent_count' => '1', 'min_pair_count' => '1']]);
    });

    it('confirms that every rule matches the baskets it was trained on', function () {
        $store = Store::factory()->create();
        [$a] = positiveLiftFixture($store);
        app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig());
        recordBaskets($store, [[$a]]);

        $diagnostics = app(AssociationRuleDiagnosticsService::class)->forStore($store);

        expect($diagnostics)->toMatchArray(['status' => 'available', 'rule_count' => 2, 'recomputed_basket_count' => 4]);
        expect($diagnostics['category_counts'][AssociationRuleDiagnosticsService::MEETS_THRESHOLDS])->toBe(2);
        expect($diagnostics['few_observations_count'])->toBe(2);
    });

    it('marks non-positive associations as weak when the lift filter is off', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig());

        // Every rule of the four-basket fixture has lift below 1.
        expect(app(AssociationRuleDiagnosticsService::class)->forStore($store)['category_counts'][AssociationRuleDiagnosticsService::WEAK_ASSOCIATION])->toBe(6);
    });

    it('flags a stored rule that no longer matches its baskets', function () {
        $store = Store::factory()->create();
        positiveLiftFixture($store);
        $ruleSet = app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig())->ruleSet;
        DB::table('association_rules')->where('rule_set_id', $ruleSet->id)->limit(1)->update(['confidence' => '0.990000']);

        $diagnostics = app(AssociationRuleDiagnosticsService::class)->forStore($store);

        expect($diagnostics['category_counts'][AssociationRuleDiagnosticsService::METRICS_MISMATCH])->toBe(1);
        expect($diagnostics['rules'][0]['category'])->toBe(AssociationRuleDiagnosticsService::METRICS_MISMATCH);
        expect(AssociationRule::query()->where('confidence', '0.990000')->count())->toBe(1);
    });

    it('judges rules against the currently configured thresholds', function () {
        $store = Store::factory()->create();
        positiveLiftFixture($store);
        app(AssociationRuleTrainer::class)->trainStore($store, AssociationRuleThresholds::fromConfig());
        config(['association_rules.min_confidence' => '0.7']);

        $counts = app(AssociationRuleDiagnosticsService::class)->forStore($store)['category_counts'];

        // B → A has confidence 1; A → B has 2/3.
        expect([$counts[AssociationRuleDiagnosticsService::MEETS_THRESHOLDS], $counts[AssociationRuleDiagnosticsService::LOW_CONFIDENCE]])->toBe([1, 1]);
    });

    it('explains when there is nothing to check', function () {
        $store = Store::factory()->create();
        expect(app(AssociationRuleDiagnosticsService::class)->forStore($store)['status'])->toBe('no_active_model');

        RuleSet::factory()->active()->create(['store_id' => $store->id, 'source_cutoff_purchase_id' => 0]);
        expect(app(AssociationRuleDiagnosticsService::class)->forStore($store)['status'])->toBe('no_rules');
    });
});

describe('page and security', function () {
    it('shows the owner their own analytics, model, and rule checks', function () {
        config(['association_rules.min_pair_count' => '2']);
        [$store, $a, $b] = octoberFixture();
        publishRules($store, [[$a, $b]])->forceFill(['eligible_basket_count' => 123])->save();

        $this->actingAs($store->owner)
            ->get(route('purchase-analytics.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertSee('Purchase Analytics')
            ->assertSee('Pancit Canton + Ligo Sardines')
            ->assertSee('Baskets used for training')
            ->assertSee('123')
            ->assertSee('This store only. There is no shared or global model.')
            ->assertSee('Run offline evaluation')
            ->assertDontSee('Maria Santos');
    });

    it('defaults to the last 90 days and rejects invalid ranges', function () {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)->get(route('purchase-analytics.index'))
            ->assertOk()
            ->assertSee('value="'.now()->subDays(89)->toDateString().'"', false)
            ->assertSee('No purchases with products have been recorded yet.');
        $this->actingAs($store->owner)->get(route('purchase-analytics.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
        $this->actingAs($store->owner)->get(route('purchase-analytics.index', ['from' => '2023-01-01', 'to' => '2026-10-01']))
            ->assertSessionHasErrors(['from' => 'Choose a period of two years or less.']);
        $this->actingAs($store->owner)->get(route('purchase-analytics.index', ['from' => '10/01/2026']))
            ->assertSessionHasErrors('from');
    });

    it('shows truthful empty states for a period without purchases and a store without a model', function () {
        [$store] = octoberFixture();
        RuleSet::factory()->create(['store_id' => $store->id, 'status' => RuleSet::STATUS_INSUFFICIENT_DATA, 'eligible_basket_count' => 4]);

        $this->actingAs($store->owner)
            ->get(route('purchase-analytics.index', ['from' => '2025-01-01', 'to' => '2025-01-31']))
            ->assertOk()
            ->assertSee('No eligible purchases fall in this period.')
            ->assertSee('found 4 eligible baskets; at least 100 are needed', false)
            ->assertSee('This store has no active recommendation model, so there are no rules to check.');
    });

    it('never shows another store\'s data, whatever the request says', function () {
        [$storeA] = octoberFixture();
        $storeB = Store::factory()->create();
        $secret = Product::factory()->for($storeB)->create(['name' => 'Store B Secret Product']);
        analyticsBasket($storeB, [[$secret, '1']], '2026-10-03');
        publishRules($storeB, [[$secret, Product::factory()->for($storeB)->create()]])->forceFill(['eligible_basket_count' => 999])->save();

        $this->actingAs($storeA->owner)
            ->get(route('purchase-analytics.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'store_id' => $storeB->id, 'store' => $storeB->id]))
            ->assertOk()
            ->assertDontSee('Store B Secret Product')
            ->assertDontSee('999')
            ->assertSee('Pancit Canton');
    });

    it('is only for signed-in store owners', function () {
        $this->get(route('purchase-analytics.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('purchase-analytics.index'))->assertForbidden();
    });

    it('is read-only, even when the evaluation runs', function () {
        [$store, $a, $b] = octoberFixture();
        $active = publishRules($store, [[$a, $b]]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) && ! str_contains($query->sql, 'sessions')) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($store->owner)->get(route('purchase-analytics.index', ['from' => '2026-09-01', 'to' => '2026-10-31', 'evaluate' => 1]))->assertOk();

        expect($writes)->toBe([]);
        expect(RuleSet::query()->pluck('status', 'id')->all())->toBe([$active->id => RuleSet::STATUS_ACTIVE]);
    });

    it('links the page from the dashboard without changing the financial figures', function () {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('purchase-analytics.index'))
            ->assertSee('Outstanding principal');
    });
});
