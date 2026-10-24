<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\CashPurchaseService;
use App\Services\LedgerService;
use App\Services\PurchaseBasketCounter;
use App\Services\SyntheticBasketGenerator;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Seeds a SYNTHETIC sari-sari catalog and purchase baskets into a
 * development test store, for testing the association rule engine
 * (DUMS-39/40). Meant for an isolated test database; refused in production
 * and never called by DatabaseSeeder.
 *
 * Safety:
 * - `--store` and an exact `--expect-name` are required; a wrong or
 *   similarly named store is refused. Stores are never created here.
 * - Without `--execute` it only previews; nothing is written.
 * - Catalog products are created only when the store has no product of
 *   that name; existing products are never changed, and existing products
 *   that are inactive or unpriced are left out of the baskets.
 * - Cash baskets go through CashPurchaseService (walk-in, catalog prices).
 *   Utang baskets go through LedgerService for clearly named synthetic
 *   customers (`Synthetic Customer NN`, @example.test, no repayment
 *   deadline), so no real customer's balance is touched and no payments are
 *   invented.
 * - Synthetic cash sales are then backdated to their planned date. Only
 *   `purchase_date` changes, and nothing in the application reads it except
 *   the record itself.
 * - Each basket has a deterministic synthetic idempotency key; baskets
 *   already recorded are skipped, so reruns never duplicate data.
 * - There is deliberately no reset option: once credits exist, cleanup
 *   cannot be guaranteed safe. Discard the isolated test database instead.
 *
 * `--catalog-only` adds just the catalog products, labelled as starter
 * items whose prices the owner should check, with no baskets, customers,
 * or credits. `--skip` leaves out named catalog products, for example ones
 * the store already sells under another name.
 */
class SeedSyntheticBaskets extends Command
{
    public const MAX_COUNT = 2000;

    public const CUSTOMER_COUNT = 12;

    /**
     * @var string
     */
    protected $signature = 'apriori:seed-synthetic-baskets
        {--store= : Target test store ID}
        {--expect-name= : The target store\'s exact name, as a safety check}
        {--count=300 : Number of synthetic baskets}
        {--seed=4040 : Random seed; the same seed gives the same plan}
        {--days=90 : History window in days for basket dates}
        {--credit-share=25 : Percentage of baskets recorded as utang for synthetic customers (0 for cash only)}
        {--catalog-only : Add only the catalog products, labelled as starter items (no baskets, customers, or credits)}
        {--skip=* : Catalog product name to leave out (repeatable)}
        {--execute : Write the data (without it, only a preview is shown)}';

    /**
     * @var string
     */
    protected $description = 'Preview or seed a synthetic catalog and purchase baskets into a test store for association rule testing';

    public function handle(SyntheticBasketGenerator $generator, CashPurchaseService $cashPurchaseService, LedgerService $ledgerService, PurchaseBasketCounter $basketCounter): int
    {
        if (app()->isProduction()) {
            $this->error('Synthetic baskets are never created in production.');

            return self::FAILURE;
        }

        $store = $this->resolveStore();
        $options = $this->numericOptions();

        if ($store === null || $options === null) {
            return self::INVALID;
        }

        [$count, $seed, $days, $creditShare] = $options;
        $execute = (bool) $this->option('execute');
        $skipNames = array_values(array_unique((array) $this->option('skip')));
        $unknownSkips = array_diff($skipNames, array_keys(SyntheticBasketGenerator::CATALOG));

        if ($unknownSkips !== []) {
            $this->error('Not catalog product names: '.implode(', ', $unknownSkips).'. Nothing was done.');

            return self::INVALID;
        }

        $catalog = $this->catalogStatus($store, $skipNames);

        if ($this->option('catalog-only')) {
            return $this->addCatalogOnly($store, $catalog, $execute);
        }

        $eligibleBefore = $basketCounter->count($store->id, 1)['counts']->basketCount;
        $this->line("Store: #{$store->id} {$store->store_name} | eligible baskets now: {$eligibleBefore}");

        $usableNames = $catalog['usable']->keys()->all();
        $plannedNames = [...$usableNames, ...$catalog['missing']];
        $customerSlots = $creditShare > 0 ? self::CUSTOMER_COUNT : 0;
        $plan = $generator->plan($store->id, $plannedNames, $count, $seed, $days, $creditShare, $customerSlots);
        $this->printPlan($catalog, $plan, $generator->patternCoverage($plannedNames), $count);

        if (count($plannedNames) < 60) {
            $this->error('Fewer than 60 catalog products would be usable in this store. Nothing was done.');

            return self::INVALID;
        }

        if (! $execute) {
            $this->warn('Preview only: nothing was written. Add --execute to seed this synthetic data.');

            return self::SUCCESS;
        }

        $products = $this->createMissingProducts($store, $catalog, SyntheticBasketGenerator::CATALOG_TAG);
        $customers = $customerSlots > 0 ? $this->syntheticCustomers($store) : collect();
        $existingKeys = $this->existingKeys($store, array_column($plan, 'key'));
        $created = ['cash' => 0, 'utang' => 0];
        $skipped = 0;
        $errors = [];

        foreach ($plan as $basket) {
            if ($existingKeys->has($basket['key'])) {
                $skipped++;

                continue;
            }

            try {
                $basket['type'] === 'utang'
                    ? $this->recordCredit($ledgerService, $store, $customers[$basket['customer_slot']], $products, $basket)
                    : $this->recordCash($cashPurchaseService, $store, $products, $basket);
                $created[$basket['type']]++;
            } catch (Throwable $exception) {
                $errors[] = "basket {$basket['index']}: ".class_basename($exception).': '.$exception->getMessage();
            }
        }

        $eligibleAfter = $basketCounter->count($store->id, 1)['counts']->basketCount;
        $this->table(['Result', 'Count'], [
            ['catalog products created', $catalog['missing'] === [] ? 0 : count($catalog['missing'])],
            ['baskets planned', count($plan)],
            ['cash baskets created', $created['cash']],
            ['utang baskets created', $created['utang']],
            ['skipped (already recorded)', $skipped],
            ['errors', count($errors)],
            ['eligible baskets before', $eligibleBefore],
            ['eligible baskets after', $eligibleAfter],
        ]);

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveStore(): ?Store
    {
        $storeOption = $this->option('store');
        $expectedName = $this->option('expect-name');

        if (! ctype_digit((string) $storeOption) || blank($expectedName)) {
            $this->error('Both --store=ID and --expect-name="Exact store name" are required.');

            return null;
        }

        $store = Store::query()->find((int) $storeOption);

        if ($store === null) {
            $this->error("Store {$storeOption} does not exist. This command never creates stores.");

            return null;
        }

        if ($store->store_name !== $expectedName) {
            $this->error("Store {$store->id} is named \"{$store->store_name}\", not \"{$expectedName}\". Nothing was done.");

            return null;
        }

        return $store;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function numericOptions(): ?array
    {
        $values = [$this->option('count'), $this->option('seed'), $this->option('days'), $this->option('credit-share')];

        if (! collect($values)->every(fn ($value): bool => ctype_digit((string) $value))) {
            $this->error('--count, --seed, --days, and --credit-share must be whole numbers.');

            return null;
        }

        [$count, $seed, $days, $creditShare] = array_map('intval', $values);

        if ($count < 1 || $count > self::MAX_COUNT || $days < 1 || $days > 730 || $creditShare > 100) {
            $this->error('--count must be 1 to '.self::MAX_COUNT.', --days 1 to 730, and --credit-share 0 to 100.');

            return null;
        }

        return [$count, $seed, $days, $creditShare];
    }

    /**
     * Add only the missing catalog products, as starter items.
     *
     * @param  array{usable: Collection<string, Product>, missing: list<string>, unusable: list<string>, skipped: list<string>}  $catalog
     */
    private function addCatalogOnly(Store $store, array $catalog, bool $execute): int
    {
        $this->line("Store: #{$store->id} {$store->store_name} | catalog only: no baskets, customers, or credits");
        $this->table(['Catalog', 'Count'], [
            ['products to add', count($catalog['missing'])],
            ['already in the store (same name)', $catalog['usable']->count() + count($catalog['unusable'])],
            ['left out with --skip', count($catalog['skipped'])],
        ]);

        if ($catalog['skipped'] !== []) {
            $this->line('Skipped: '.implode('; ', $catalog['skipped']));
        }

        if (! $execute) {
            $this->line('Would add: '.implode('; ', $catalog['missing']));
            $this->warn('Preview only: nothing was written. Add --execute to add these products.');

            return self::SUCCESS;
        }

        $this->createMissingProducts($store, $catalog, SyntheticBasketGenerator::STARTER_TAG);
        $this->info('Added '.count($catalog['missing']).' products. Prices are estimates: review them on the Products page.');

        return self::SUCCESS;
    }

    /**
     * Which catalog products exist (usable or not) and which must be created.
     *
     * @param  list<string>  $skipNames
     * @return array{usable: Collection<string, Product>, missing: list<string>, unusable: list<string>, skipped: list<string>}
     */
    private function catalogStatus(Store $store, array $skipNames = []): array
    {
        $existing = $store->products()
            ->whereIn('normalized_name', array_map(fn (string $name): string => Product::normalizeName($name), array_keys(SyntheticBasketGenerator::CATALOG)))
            ->get()
            ->keyBy('normalized_name');
        $usable = collect();
        $missing = [];
        $unusable = [];

        foreach (array_keys(SyntheticBasketGenerator::CATALOG) as $name) {
            if (in_array($name, $skipNames, true)) {
                continue;
            }

            $product = $existing->get(Product::normalizeName($name));

            if ($product === null) {
                $missing[] = $name;
            } elseif ($product->status === Product::STATUS_ACTIVE && $product->unit_price !== null && Money::toCents($product->unit_price) > 0) {
                $usable->put($name, $product);
            } else {
                $unusable[] = $name;
            }
        }

        return ['usable' => $usable, 'missing' => $missing, 'unusable' => $unusable, 'skipped' => $skipNames];
    }

    /**
     * @param  array{usable: Collection<string, Product>, missing: list<string>, unusable: list<string>, skipped: list<string>}  $catalog
     * @return Collection<string, Product>
     */
    private function createMissingProducts(Store $store, array $catalog, string $description): Collection
    {
        $products = $catalog['usable'];

        foreach ($catalog['missing'] as $name) {
            $products->put($name, $store->products()->create([
                'name' => $name,
                'unit_price' => SyntheticBasketGenerator::CATALOG[$name][0],
                'description' => $description,
            ]));
        }

        return $products;
    }

    /**
     * Clearly named synthetic customers, created once and reused.
     *
     * @return Collection<int, Customer> keyed by slot (1-based)
     */
    private function syntheticCustomers(Store $store): Collection
    {
        return collect(range(1, self::CUSTOMER_COUNT))->mapWithKeys(function (int $slot) use ($store): array {
            $number = str_pad((string) $slot, 2, '0', STR_PAD_LEFT);

            return [$slot => $store->customers()->firstOrCreate(
                ['email' => "synthetic-customer-{$number}@example.test"],
                [
                    'full_name' => SyntheticBasketGenerator::CUSTOMER_NAME_PREFIX.$number,
                    'contact_number' => '090000000'.$number,
                    'residential_landmark' => 'Synthetic test data (apriori:seed-synthetic-baskets)',
                    'credit_limit' => '100000.00',
                ],
            )];
        });
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<string, int>
     */
    private function existingKeys(Store $store, array $keys): Collection
    {
        $cashKeys = Purchase::query()->where('store_id', $store->id)->whereIn('idempotency_key', $keys)->pluck('idempotency_key');
        $creditKeys = LedgerEntry::query()
            ->whereIn('idempotency_key', $keys)
            ->whereHas('customer', fn ($query) => $query->where('store_id', $store->id))
            ->pluck('idempotency_key');

        return $cashKeys->merge($creditKeys)->flip();
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  array{index: int, key: string, days_ago: int, items: list<array{name: string, quantity: string}>}  $basket
     */
    private function recordCash(CashPurchaseService $cashPurchaseService, Store $store, Collection $products, array $basket): void
    {
        DB::transaction(function () use ($cashPurchaseService, $store, $products, $basket): void {
            $purchase = $cashPurchaseService->record($store, $store->owner, [
                'idempotency_key' => $basket['key'],
                'customer_id' => null,
                'items' => $this->items($products, $basket, withPrices: true),
                'expected_total' => Money::fromCents($this->totalCents($products, $basket)),
            ]);

            $purchase->forceFill(['purchase_date' => now()->subDays($basket['days_ago'])->toDateString()])->save();
        });
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  array{index: int, key: string, days_ago: int, items: list<array{name: string, quantity: string}>}  $basket
     */
    private function recordCredit(LedgerService $ledgerService, Store $store, Customer $customer, Collection $products, array $basket): void
    {
        $ledgerService->recordCredit($customer, [
            'amount' => Money::fromCents($this->totalCents($products, $basket)),
            'items' => $this->items($products, $basket, withPrices: false),
            'transaction_date' => now()->subDays($basket['days_ago'])->toDateString(),
            'idempotency_key' => $basket['key'],
            'recorded_by_user_id' => $store->owner_user_id,
        ]);
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  array{items: list<array{name: string, quantity: string}>}  $basket
     * @return list<array{product_id: int, quantity: string, unit_price?: string}>
     */
    private function items(Collection $products, array $basket, bool $withPrices): array
    {
        return array_map(function (array $item) use ($products, $withPrices): array {
            $product = $products->get($item['name']);
            $line = ['product_id' => $product->id, 'quantity' => $item['quantity']];

            return $withPrices ? [...$line, 'unit_price' => $product->unit_price] : $line;
        }, $basket['items']);
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  array{items: list<array{name: string, quantity: string}>}  $basket
     */
    private function totalCents(Collection $products, array $basket): int
    {
        return array_sum(array_map(
            fn (array $item): int => Money::lineTotalCents(Money::toCents($products->get($item['name'])->unit_price), Quantity::toThousandths($item['quantity'])),
            $basket['items'],
        ));
    }

    /**
     * @param  array{usable: Collection<string, Product>, missing: list<string>, unusable: list<string>}  $catalog
     * @param  list<array{type: string, items: list<array{name: string, quantity: string}>}>  $plan
     * @param  array{used: list<string>, skipped: list<string>}  $coverage
     */
    private function printPlan(array $catalog, array $plan, array $coverage, int $count): void
    {
        $sizes = array_count_values(array_map(fn (array $basket): int => count($basket['items']), $plan));
        ksort($sizes);
        $types = array_count_values(array_column($plan, 'type'));

        $this->table(['Plan', 'Value'], [
            ['catalog products already in the store (usable)', $catalog['usable']->count()],
            ['catalog products to create', count($catalog['missing'])],
            ['catalog names left out (existing but inactive or unpriced)', count($catalog['unusable'])],
            ['baskets', $count],
            ['cash / utang baskets', ($types['cash'] ?? 0).' / '.($types['utang'] ?? 0)],
            ['item rows', array_sum(array_map(fn (array $basket): int => count($basket['items']), $plan))],
            ['distinct products in baskets', count(array_unique(array_merge(...array_map(fn (array $basket): array => array_column($basket['items'], 'name'), $plan))))],
            ['basket sizes', collect($sizes)->map(fn (int $baskets, int $size): string => "{$size}: {$baskets}")->implode(', ')],
            ['recurring patterns available / skipped', count($coverage['used']).' / '.count($coverage['skipped'])],
        ]);
    }
}
