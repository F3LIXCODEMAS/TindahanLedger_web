<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RuleSet;
use App\Models\Store;
use App\Services\RecommendationEvaluationService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['association_rules' => [...config('association_rules'), 'min_support' => '0', 'min_confidence' => '0', 'min_lift' => 'off', 'min_baskets' => '1', 'min_antecedent_count' => '1', 'min_pair_count' => '1']]);
});

/**
 * Record `$times` cash baskets on `$date`, each with the products in entry order.
 *
 * @param  list<Product>  $products
 */
function datedBaskets(Store $store, string $date, array $products, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $purchase = Purchase::factory()->cash()->create(['store_id' => $store->id, 'purchase_date' => $date]);

        foreach ($products as $product) {
            PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $product->id]);
        }
    }
}

/**
 * Training day (Jan 1): 15 × {A,B}, 15 × {C,E}, and single baskets of the
 * popular F (40), G (30), H (25). Held-out day (Feb 1): 10 × {A,B} and
 * 10 × {C,E}, so B or E is the hidden product.
 *
 * @return array<string, Product>
 */
function evaluationFixture(Store $store): array
{
    $products = collect(['A', 'B', 'C', 'E', 'F', 'G', 'H'])
        ->mapWithKeys(fn (string $name): array => [$name => Product::factory()->for($store)->create(['name' => "Product {$name}"])])
        ->all();

    datedBaskets($store, '2026-01-01', [$products['A'], $products['B']], 15);
    datedBaskets($store, '2026-01-01', [$products['C'], $products['E']], 15);
    datedBaskets($store, '2026-01-01', [$products['F']], 40);
    datedBaskets($store, '2026-01-01', [$products['G']], 30);
    datedBaskets($store, '2026-01-01', [$products['H']], 25);
    datedBaskets($store, '2026-02-01', [$products['A'], $products['B']], 10);
    datedBaskets($store, '2026-02-01', [$products['C'], $products['E']], 10);

    return $products;
}

function evaluator(): RecommendationEvaluationService
{
    return app(RecommendationEvaluationService::class);
}

it('matches the hand-calculated metrics for both methods on the same baskets', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);

    $result = evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    expect($result)->toMatchArray(['status' => 'available', 'label' => 'exploratory', 'training_rule_count' => 4]);
    expect($result['split'])->toMatchArray([
        'eligible_baskets' => 145,
        'training_baskets' => 125,
        'training_period' => ['2026-01-01', '2026-01-01'],
        'held_out_baskets' => 20,
        'held_out_period' => ['2026-02-01', '2026-02-01'],
        'evaluated_baskets' => 20,
        'single_item_held_out_baskets' => 0,
    ]);
    expect($result['methods']['apriori'])->toBe([
        'baskets_with_suggestions' => 20, 'hits' => 20,
        'coverage' => '1.000000', 'hit_rate' => '1.000000', 'precision' => '0.333333', 'recall' => '1.000000',
    ]);
    expect($result['methods']['popularity'])->toBe([
        'baskets_with_suggestions' => 20, 'hits' => 0,
        'coverage' => '1.000000', 'hit_rate' => '0.000000', 'precision' => '0.000000', 'recall' => '0.000000',
    ]);
});

it('never uses held-out baskets for rules or for the popularity ranking', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);
    [$x, $y] = Product::factory()->count(2)->for($store)->create()->all();
    datedBaskets($store, '2026-02-02', [$x, $y], 30);

    $result = evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    // X and Y only appear after the split day: no rule and no popularity rank can suggest Y.
    expect($result['split']['evaluated_baskets'])->toBe(50);
    expect($result['training_rule_count'])->toBe(4);
    expect($result['methods']['apriori'])->toMatchArray(['baskets_with_suggestions' => 20, 'hits' => 20]);
    expect($result['methods']['popularity'])->toMatchArray(['hits' => 0]);
});

it('never suggests input products or products that are now inactive', function () {
    $store = Store::factory()->create();
    $products = evaluationFixture($store);
    $products['B']->forceFill(['status' => Product::STATUS_INACTIVE])->save();
    $products['F']->forceFill(['status' => Product::STATUS_INACTIVE])->save();

    $result = evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    // B can no longer be suggested, so the ten {A,B} baskets get no rule suggestion.
    expect($result['methods']['apriori'])->toMatchArray(['baskets_with_suggestions' => 10, 'hits' => 10, 'coverage' => '0.500000']);
    // Popularity now suggests G, H, and A or C (never the input product).
    expect($result['methods']['popularity'])->toMatchArray(['baskets_with_suggestions' => 20, 'hits' => 0]);
});

it('keeps a whole day in the training portion', function () {
    $store = Store::factory()->create();
    $products = evaluationFixture($store);
    datedBaskets($store, '2026-02-01', [$products['F']], 30);

    $result = evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    expect($result['split']['training_period'])->toBe(['2026-01-01', '2026-01-01']);
    expect($result['split']['single_item_held_out_baskets'])->toBe(30);
    expect($result['split']['evaluated_baskets'])->toBe(20);
});

it('gives the same result every time', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);

    expect(evaluator()->evaluate($store, '2026-01-01', '2026-02-28'))->toBe(evaluator()->evaluate($store, '2026-01-01', '2026-02-28'));
});

it('counts and loads evaluation baskets inside one transaction', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);
    $outerLevel = DB::transactionLevel();
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'purchase_items')) {
            $levels[] = DB::transactionLevel();
        }
    });

    evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    expect($levels)->toHaveCount(2)->each->toBe($outerLevel + 1);
});

it('explains why evaluation is unavailable instead of inventing results', function (Closure $setUp, string $reason) {
    $store = Store::factory()->create();
    $setUp($store);

    $result = evaluator()->evaluate($store, '2026-01-01', '2026-02-28');

    expect($result['status'])->toBe('unavailable');
    expect(implode(' ', $result['reasons']))->toContain($reason);
    expect($result)->not->toHaveKeys(['methods', 'label']);
})->with([
    'no baskets' => [fn (Store $store) => null, 'no eligible purchase baskets'],
    'all on one day' => [fn (Store $store) => datedBaskets($store, '2026-01-01', Product::factory()->count(2)->for($store)->create()->all(), 40), 'nothing is left to evaluate'],
    'too few later baskets' => [function (Store $store) {
        [$a, $b] = Product::factory()->count(2)->for($store)->create()->all();
        datedBaskets($store, '2026-01-01', [$a, $b], 40);
        datedBaskets($store, '2026-02-01', [$a, $b], 5);
    }, 'Only 5 later baskets have two or more products'],
]);

it('requires the engine\'s minimum basket count in the training portion', function () {
    config(['association_rules.min_baskets' => '100']);
    $store = Store::factory()->create();
    [$a, $b] = Product::factory()->count(2)->for($store)->create()->all();
    datedBaskets($store, '2026-01-01', [$a, $b], 60);
    datedBaskets($store, '2026-02-01', [$a, $b], 25);

    expect(evaluator()->evaluate($store, '2026-01-01', '2026-02-28')['reasons'])->toBe(['The training portion has 60 baskets; the engine needs at least 100 to train.']);
});

it('only uses the store\'s own baskets', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);
    evaluationFixture(Store::factory()->create());

    expect(evaluator()->evaluate($store, '2026-01-01', '2026-02-28')['split']['eligible_baskets'])->toBe(145);
});

it('changes nothing: no writes, and the active model stays active', function () {
    $store = Store::factory()->create();
    $products = evaluationFixture($store);
    $active = publishRules($store, [[$products['A'], $products['C']]]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('apriori:evaluate', ['--store' => $store->id, '--from' => '2026-01-01', '--to' => '2026-02-28'])
        ->expectsOutputToContain('Exploratory: fewer than 100 baskets were evaluated.')
        ->expectsOutputToContain('it does not show effects on sales or customers')
        ->assertSuccessful();

    expect($writes)->toBe([]);
    expect(RuleSet::query()->pluck('status', 'id')->all())->toBe([$active->id => RuleSet::STATUS_ACTIVE]);
});

it('reports unavailable data and invalid options through exit codes', function () {
    $store = Store::factory()->create();

    $this->artisan('apriori:evaluate', ['--store' => $store->id, '--from' => '2026-01-01', '--to' => '2026-02-28'])
        ->expectsOutputToContain('There are no eligible purchase baskets in this period.')
        ->assertFailed();
    $this->artisan('apriori:evaluate', ['--store' => '999'])->assertExitCode(2);
    $this->artisan('apriori:evaluate', ['--store' => $store->id, '--from' => '2026-03-01', '--to' => '2026-02-01'])->assertExitCode(2);
});

it('shows the evaluation on the analytics page only when asked', function () {
    $store = Store::factory()->create();
    evaluationFixture($store);
    $query = ['from' => '2026-01-01', 'to' => '2026-02-28'];

    $this->actingAs($store->owner)->get(route('purchase-analytics.index', $query))
        ->assertOk()
        ->assertSee('Run offline evaluation')
        ->assertDontSee('Hit rate@3');
    $this->actingAs($store->owner)->get(route('purchase-analytics.index', [...$query, 'evaluate' => 1]))
        ->assertOk()
        ->assertSee('Hit rate@3')
        ->assertSee('Exploratory: fewer than 100 baskets evaluated')
        ->assertSee('100.0%')
        ->assertDontSee('accuracy');
});
