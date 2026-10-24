<?php

namespace App\Services;

use App\Models\AssociationRule;
use App\Models\Purchase;
use App\Models\RuleSet;
use App\Models\Store;
use App\Support\AssociationMetrics;
use App\Support\AssociationRuleThresholds;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Purchase pattern figures for one store and reporting period (DUMS-41).
 *
 * Every figure uses PurchaseBasketCounter::eligibleItems(), the same basket
 * eligibility as the association rule engine, filtered by `purchase_date`
 * (inclusive). Only aggregates are read: no customer data is selected.
 *
 * - Basket count: distinct eligible purchases containing a product.
 * - Item quantity: the sum of that product's recorded quantities.
 * - Pair count: distinct eligible purchases containing both products.
 *
 * Pairs are listed only when they appear together in at least the engine's
 * minimum co-occurrence count. A pair's confidence and lift are shown only
 * from the store's active rule set (rules trained on the model's own
 * baskets), never invented for the selected period.
 */
class PurchasePatternAnalyticsService
{
    public const PRODUCT_LIMIT = 10;

    public const PAIR_LIMIT = 10;

    public function __construct(private PurchaseBasketCounter $basketCounter) {}

    /**
     * @return array{
     *     basket_count: int,
     *     distinct_products: int,
     *     baskets_by_type: array{cash: int, utang: int},
     *     top_products: list<array{product_id: int, name: string, basket_count: int, total_quantity: string, basket_share: string}>,
     *     top_pairs: list<array{first_product_id: int, first_name: string, second_product_id: int, second_name: string, pair_count: int, support: string, rules: list<array{antecedent_product_id: int, consequent_product_id: int, confidence: string, lift: string}>}>,
     *     min_pair_count: int
     * }
     */
    public function summary(Store $store, string $fromDate, string $toDate): array
    {
        $storeId = (int) $store->getKey();
        $items = fn () => $this->basketCounter->eligibleItems($storeId, null, $fromDate, $toDate);
        $basketCount = (int) $items()->distinct()->count('purchase_items.purchase_id');
        $minPairCount = AssociationRuleThresholds::fromConfig()->minPairCount;

        if ($basketCount === 0) {
            return [
                'basket_count' => 0,
                'distinct_products' => 0,
                'baskets_by_type' => ['cash' => 0, 'utang' => 0],
                'top_products' => [],
                'top_pairs' => [],
                'min_pair_count' => $minPairCount,
            ];
        }

        $byType = $items()
            ->select('purchases.purchase_type', DB::raw('count(distinct purchase_items.purchase_id) as baskets'))
            ->groupBy('purchases.purchase_type')
            ->pluck('baskets', 'purchase_type');

        return [
            'basket_count' => $basketCount,
            'distinct_products' => (int) $items()->distinct()->count('purchase_items.product_id'),
            'baskets_by_type' => [
                'cash' => (int) ($byType[Purchase::TYPE_CASH] ?? 0),
                'utang' => (int) ($byType[Purchase::TYPE_UTANG] ?? 0),
            ],
            'top_products' => $this->topProducts($items(), $basketCount),
            'top_pairs' => $this->topPairs($storeId, $items(), $basketCount, $minPairCount),
            'min_pair_count' => $minPairCount,
        ];
    }

    /**
     * All-time totals that tell the empty states apart: no purchases at all,
     * purchases without eligible items, or simply none in the period.
     *
     * @return array{purchases: int, eligible_baskets: int}
     */
    public function storeTotals(Store $store): array
    {
        $storeId = (int) $store->getKey();

        return [
            'purchases' => Purchase::query()->where('store_id', $storeId)->count(),
            'eligible_baskets' => (int) $this->basketCounter->eligibleItems($storeId)->distinct()->count('purchase_items.purchase_id'),
        ];
    }

    /**
     * @return list<array{product_id: int, name: string, basket_count: int, total_quantity: string, basket_share: string}>
     */
    private function topProducts(Builder $items, int $basketCount): array
    {
        return $items
            ->select(
                'purchase_items.product_id',
                'products.name',
                DB::raw('count(distinct purchase_items.purchase_id) as basket_count'),
                DB::raw('sum(purchase_items.quantity) as total_quantity'),
            )
            ->groupBy('purchase_items.product_id', 'products.name')
            ->orderByDesc('basket_count')
            ->orderByDesc('total_quantity')
            ->orderBy('purchase_items.product_id')
            ->limit(self::PRODUCT_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'basket_count' => (int) $row->basket_count,
                'total_quantity' => $this->formatQuantity((string) $row->total_quantity),
                'basket_share' => AssociationMetrics::ratio((int) $row->basket_count, $basketCount),
            ])
            ->all();
    }

    /**
     * @return list<array{first_product_id: int, first_name: string, second_product_id: int, second_name: string, pair_count: int, support: string, rules: list<array{antecedent_product_id: int, consequent_product_id: int, confidence: string, lift: string}>}>
     */
    private function topPairs(int $storeId, Builder $items, int $basketCount, int $minPairCount): array
    {
        $eligible = $items->select('purchase_items.purchase_id', 'purchase_items.product_id')->distinct();

        $pairs = DB::query()->fromSub($eligible, 'first_items')
            ->joinSub($eligible, 'second_items', function (JoinClause $join): void {
                $join->on('second_items.purchase_id', '=', 'first_items.purchase_id')
                    ->whereColumn('second_items.product_id', '>', 'first_items.product_id');
            })
            ->join('products as first_products', 'first_products.id', '=', 'first_items.product_id')
            ->join('products as second_products', 'second_products.id', '=', 'second_items.product_id')
            ->where('first_products.store_id', $storeId)
            ->where('second_products.store_id', $storeId)
            ->select(
                'first_items.product_id as first_product_id',
                'first_products.name as first_name',
                'second_items.product_id as second_product_id',
                'second_products.name as second_name',
                DB::raw('count(*) as pair_count'),
            )
            ->groupBy('first_items.product_id', 'first_products.name', 'second_items.product_id', 'second_products.name')
            ->havingRaw('count(*) >= ?', [$minPairCount])
            ->orderByDesc('pair_count')
            ->orderBy('first_items.product_id')
            ->orderBy('second_items.product_id')
            ->limit(self::PAIR_LIMIT)
            ->get();

        $rules = $this->activeRulesFor($storeId, $pairs->pluck('first_product_id')->merge($pairs->pluck('second_product_id'))->unique()->all());

        return $pairs->map(function (object $pair) use ($basketCount, $rules): array {
            $first = (int) $pair->first_product_id;
            $second = (int) $pair->second_product_id;

            return [
                'first_product_id' => $first,
                'first_name' => $pair->first_name,
                'second_product_id' => $second,
                'second_name' => $pair->second_name,
                'pair_count' => (int) $pair->pair_count,
                'support' => AssociationMetrics::ratio((int) $pair->pair_count, $basketCount),
                'rules' => array_values(array_filter([$rules["{$first}:{$second}"] ?? null, $rules["{$second}:{$first}"] ?? null])),
            ];
        })->all();
    }

    /**
     * Active-model rules between the given products, keyed "antecedent:consequent".
     *
     * @param  list<int>  $productIds
     * @return array<string, array{antecedent_product_id: int, consequent_product_id: int, confidence: string, lift: string}>
     */
    private function activeRulesFor(int $storeId, array $productIds): array
    {
        $ruleSetId = RuleSet::query()->activeForStore($storeId)->value('id');

        if ($ruleSetId === null || $productIds === []) {
            return [];
        }

        return AssociationRule::query()
            ->where('rule_set_id', $ruleSetId)
            ->where('store_id', $storeId)
            ->whereIn('antecedent_product_id', $productIds)
            ->whereIn('consequent_product_id', $productIds)
            ->get(['antecedent_product_id', 'consequent_product_id', 'confidence', 'lift'])
            ->mapWithKeys(fn (AssociationRule $rule): array => ["{$rule->antecedent_product_id}:{$rule->consequent_product_id}" => [
                'antecedent_product_id' => $rule->antecedent_product_id,
                'consequent_product_id' => $rule->consequent_product_id,
                'confidence' => $rule->confidence,
                'lift' => $rule->lift,
            ]])
            ->all();
    }

    /**
     * A summed decimal(8,3) quantity without trailing zeros, e.g. "12" or "2.25".
     */
    private function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
