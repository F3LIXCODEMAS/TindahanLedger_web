<?php

use App\Models\AssociationRule;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RuleSet;
use App\Models\Store;
use App\Models\SukiPoint;
use App\Services\PurchaseBasketCounter;
use App\Services\SyntheticBasketGenerator;
use App\Support\AssociationRuleThresholds;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Testing\PendingCommand;

/**
 * @param  array<string, mixed>  $options
 */
function seedSynthetic(Store $store, array $options = []): PendingCommand
{
    return test()->artisan('apriori:seed-synthetic-baskets', ['--store' => $store->id, '--expect-name' => $store->store_name, ...$options]);
}

function eligibleBaskets(Store $store): int
{
    return app(PurchaseBasketCounter::class)->count($store->id, 1)['counts']->basketCount;
}

describe('safety checks', function () {
    it('requires both a store ID and the expected store name', function (array $options) {
        Store::factory()->create(['store_name' => 'Mang Arthur Store']);

        $this->artisan('apriori:seed-synthetic-baskets', [...$options, '--execute' => true])
            ->expectsOutputToContain('Both --store=ID and --expect-name="Exact store name" are required.')
            ->assertExitCode(2);

        expect(Product::query()->count())->toBe(0);
    })->with([
        'nothing' => [[]],
        'store only' => [['--store' => '1']],
        'name only' => [['--expect-name' => 'Mang Arthur Store']],
    ]);

    it('refuses a missing store and never creates one', function () {
        $this->artisan('apriori:seed-synthetic-baskets', ['--store' => '999', '--expect-name' => 'Mang Arthur Store', '--execute' => true])
            ->expectsOutputToContain('Store 999 does not exist. This command never creates stores.')
            ->assertExitCode(2);

        expect(Store::query()->count())->toBe(0);
    });

    it('refuses a store whose name does not match exactly', function (string $expectedName) {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store 2']);

        $this->artisan('apriori:seed-synthetic-baskets', ['--store' => $store->id, '--expect-name' => $expectedName, '--execute' => true])
            ->expectsOutputToContain('Nothing was done.')
            ->assertExitCode(2);

        expect(Product::query()->count())->toBe(0);
    })->with(['Mang Arthur Store', 'mang arthur store 2']);

    it('refuses to run in production', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        app()->detectEnvironment(fn (): string => 'production');

        seedSynthetic($store, ['--execute' => true])->assertFailed();

        expect(Product::query()->count())->toBe(0);
    });

    it('only previews without --execute', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);

        seedSynthetic($store, ['--count' => '50'])
            ->expectsOutputToContain('Preview only: nothing was written.')
            ->assertSuccessful();

        expect(Product::query()->count())->toBe(0);
        expect(Purchase::query()->count())->toBe(0);
        expect(Customer::query()->count())->toBe(0);
    });
});

describe('seeding', function () {
    it('creates a catalog of at least 60 products and at least 100 eligible baskets of both types', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);

        seedSynthetic($store, ['--count' => '120', '--execute' => true])->assertSuccessful();

        $products = Product::query()->where('store_id', $store->id)->get();
        $purchases = Purchase::query()->where('store_id', $store->id)->with('items', 'ledgerEntry')->get();
        $items = $purchases->flatMap->items;

        expect($products->count())->toBe(count(SyntheticBasketGenerator::CATALOG))->toBeGreaterThanOrEqual(60);
        expect($products->every(fn (Product $product): bool => $product->status === Product::STATUS_ACTIVE && $product->description === SyntheticBasketGenerator::CATALOG_TAG))->toBeTrue();
        expect($purchases)->toHaveCount(120);
        expect(eligibleBaskets($store))->toBe(120);
        expect($purchases->pluck('purchase_type')->unique()->sort()->values()->all())->toBe([Purchase::TYPE_CASH, Purchase::TYPE_UTANG]);
        expect($items->every(fn (PurchaseItem $item): bool => $item->store_id === $store->id
            && $products->contains('id', $item->product_id)
            && (float) $item->quantity > 0 && floor((float) $item->quantity) === (float) $item->quantity
            && $item->unit_price_snapshot === $products->firstWhere('id', $item->product_id)->unit_price
            && Money::toCents($item->line_total) === Money::lineTotalCents(Money::toCents($item->unit_price_snapshot), Quantity::toThousandths($item->quantity))))->toBeTrue();
        expect($purchases->map(fn (Purchase $purchase): int => $purchase->items->count())->unique()->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6]);
        expect($purchases->pluck('purchase_date')->map->toDateString()->unique()->count())->toBeGreaterThan(20);
        expect($purchases->every(fn (Purchase $purchase): bool => $purchase->purchase_date->between(now()->subDays(90)->startOfDay(), now())))->toBeTrue();

        $credits = $purchases->where('purchase_type', Purchase::TYPE_UTANG);
        expect($credits->every(fn (Purchase $purchase): bool => $purchase->ledgerEntry !== null
            && str_starts_with($purchase->ledgerEntry->idempotency_key, SyntheticBasketGenerator::KEY_PREFIX)
            && Money::toCents($purchase->ledgerEntry->amount) === $purchase->items->sum(fn (PurchaseItem $item): int => Money::toCents($item->line_total))
            && str_starts_with($purchase->customer->full_name, SyntheticBasketGenerator::CUSTOMER_NAME_PREFIX)))->toBeTrue();
        expect($purchases->where('purchase_type', Purchase::TYPE_CASH)->every(fn (Purchase $purchase): bool => $purchase->customer_id === null
            && str_starts_with($purchase->idempotency_key, SyntheticBasketGenerator::KEY_PREFIX)))->toBeTrue();
        expect(Payment::query()->count())->toBe(0);
    });

    it('never duplicates products, customers, or baskets when run again', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        seedSynthetic($store, ['--count' => '40', '--execute' => true])->assertSuccessful();
        $counts = fn (): array => [Product::query()->count(), Customer::query()->count(), Purchase::query()->count(), LedgerEntry::query()->count()];
        $afterFirstRun = $counts();

        seedSynthetic($store, ['--count' => '40', '--execute' => true])->expectsOutputToContain('skipped (already recorded)')->assertSuccessful();

        expect($counts())->toBe($afterFirstRun);
    });

    it('plans the same baskets for the same seed and different ones for another seed', function () {
        $generator = new SyntheticBasketGenerator;
        $names = array_keys(SyntheticBasketGenerator::CATALOG);

        $first = $generator->plan(1, $names, 30, 4040, 90, 25, 12);

        expect($generator->plan(1, $names, 30, 4040, 90, 25, 12))->toBe($first);
        expect(array_slice($generator->plan(1, $names, 50, 4040, 90, 25, 12), 0, 30))->toBe($first);
        expect($generator->plan(1, $names, 30, 7, 90, 25, 12))->not->toBe($first);
        expect(collect($generator->plan(1, $names, 30, 4040, 90, 0, 0))->pluck('type')->unique()->all())->toBe(['cash']);
    });

    it('reuses existing same-named products without changing them and leaves out unusable ones', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        $existing = Product::factory()->for($store)->create(['name' => 'Coca-Cola Mismo 290ml', 'unit_price' => '19.00', 'description' => 'Owner product']);
        $inactive = Product::factory()->for($store)->inactive()->create(['name' => 'Choc-Nut 24g', 'unit_price' => '5.00']);

        seedSynthetic($store, ['--count' => '60', '--execute' => true])->assertSuccessful();

        expect($existing->fresh()->only(['unit_price', 'description', 'status']))->toBe(['unit_price' => '19.00', 'description' => 'Owner product', 'status' => Product::STATUS_ACTIVE]);
        expect(Product::query()->where('store_id', $store->id)->count())->toBe(count(SyntheticBasketGenerator::CATALOG));
        expect(PurchaseItem::query()->where('product_id', $inactive->id)->exists())->toBeFalse();
    });
});

describe('protection of real data', function () {
    it('leaves real customers, credits, payments, Suki Points, and other stores unchanged', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        $customer = Customer::factory()->for($store)->create(['current_balance' => '250.00']);
        $credit = LedgerEntry::factory()->for($customer)->create();
        Payment::factory()->create(['ledger_entry_id' => $credit->id]);
        SukiPoint::factory()->create(['customer_id' => $customer->id]);
        $otherStore = Store::factory()->create(['store_name' => 'Aling Nena Store']);
        recordFourBasketFixture($otherStore);
        $snapshot = fn (): array => [
            $customer->fresh()->toArray(), $credit->fresh()->toArray(), Payment::query()->get()->toArray(), SukiPoint::query()->get()->toArray(),
            Purchase::query()->where('store_id', $otherStore->id)->get()->toArray(), Product::query()->where('store_id', $otherStore->id)->get()->toArray(),
            eligibleBaskets($otherStore),
        ];
        $before = $snapshot();

        seedSynthetic($store, ['--count' => '60', '--execute' => true])->assertSuccessful();

        expect($snapshot())->toBe($before);
        expect(eligibleBaskets($otherStore))->toBe(4);
    });

    it('feeds the engine without persisting or activating anything on a dry run, keeping the thresholds', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        $activeRuleSet = RuleSet::factory()->active()->create(['store_id' => $store->id]);
        $thresholdsBefore = AssociationRuleThresholds::fromConfig()->toArray();
        seedSynthetic($store, ['--count' => '120', '--execute' => true])->assertSuccessful();

        $this->artisan('apriori:train', ['--store' => $store->id, '--dry-run' => true])
            ->expectsOutputToContain('| eligible baskets | 120')
            ->expectsOutputToContain('Dry run: metrics were calculated, but nothing was saved or activated.')
            ->assertSuccessful();

        expect(RuleSet::query()->pluck('id')->all())->toBe([$activeRuleSet->id]);
        expect($activeRuleSet->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect(AssociationRule::query()->count())->toBe(0);
        expect(AssociationRuleThresholds::fromConfig()->toArray())->toBe($thresholdsBefore);
    });
});

describe('catalog only', function () {
    it('adds only the starter products, leaving out skipped ones and never touching existing products', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        $existing = Product::factory()->for($store)->create(['name' => 'Coca-Cola Mismo 290ml', 'unit_price' => '19.00', 'description' => 'Owner product']);

        seedSynthetic($store, ['--catalog-only' => true, '--skip' => ['SkyFlakes Crackers 25g', 'Fresh Egg (medium)'], '--execute' => true])
            ->expectsOutputToContain('Added 73 products.')
            ->assertSuccessful();

        $added = Product::query()->where('store_id', $store->id)->whereKeyNot($existing->id)->get();
        expect($added)->toHaveCount(count(SyntheticBasketGenerator::CATALOG) - 3);
        expect($added->pluck('description')->unique()->all())->toBe([SyntheticBasketGenerator::STARTER_TAG]);
        expect($added->pluck('name'))->not->toContain('SkyFlakes Crackers 25g', 'Fresh Egg (medium)');
        expect($existing->fresh()->only(['unit_price', 'description']))->toBe(['unit_price' => '19.00', 'description' => 'Owner product']);
        expect(Purchase::query()->count() + Customer::query()->count() + LedgerEntry::query()->count())->toBe(0);
    });

    it('previews without writing and adds nothing new when run again', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);

        seedSynthetic($store, ['--catalog-only' => true])->expectsOutputToContain('Preview only: nothing was written.')->assertSuccessful();
        expect(Product::query()->count())->toBe(0);

        seedSynthetic($store, ['--catalog-only' => true, '--execute' => true])->assertSuccessful();
        seedSynthetic($store, ['--catalog-only' => true, '--execute' => true])->expectsOutputToContain('Added 0 products.')->assertSuccessful();

        expect(Product::query()->count())->toBe(count(SyntheticBasketGenerator::CATALOG));
    });

    it('refuses skip names that are not in the catalog', function () {
        $store = Store::factory()->create(['store_name' => 'Mang Arthur Store']);

        seedSynthetic($store, ['--catalog-only' => true, '--skip' => ['Skyflakes'], '--execute' => true])
            ->expectsOutputToContain('Not catalog product names: Skyflakes. Nothing was done.')
            ->assertExitCode(2);

        expect(Product::query()->count())->toBe(0);
    });
});
