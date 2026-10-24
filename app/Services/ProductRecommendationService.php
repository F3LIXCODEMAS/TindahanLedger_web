<?php

namespace App\Services;

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Reads recommendations from a store's active rule set (DUMS-39).
 *
 * Only the store's own active version is read; external experiments and
 * other stores' rules are never returned. The selected product must belong
 * to the store, otherwise nothing is returned. Recommended products must
 * be active in the same store's catalog. Ordering: confidence, then lift,
 * then co-occurrence count (all highest first), then product ID.
 *
 * forBasket() (DUMS-40) recommends for a whole basket using the same
 * single-item rules: each candidate product is judged by its strongest rule
 * from any basket product (confidence, then lift, then co-occurrence count,
 * then the lower antecedent ID). Candidates are ranked by that rule, with
 * the lower product ID as the final tie-breaker. Metrics are never combined
 * or weighted, and products already in the basket are never recommended.
 *
 * The caller is responsible for authorizing the user for the store.
 */
class ProductRecommendationService
{
    public const MAX_LIMIT = 50;

    public const BASKET_LIMIT = 3;

    /** Same limit as the products of one recorded purchase. */
    public const MAX_BASKET_PRODUCTS = LedgerService::MAX_PURCHASE_ITEMS;

    /**
     * @return Collection<int, AssociationRule> each with `consequentProduct` loaded
     */
    public function forProduct(Store $store, Product $product, int $limit = 5): Collection
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('The limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        $storeId = (int) $store->getKey();

        if ((int) $product->store_id !== $storeId) {
            return new Collection;
        }

        $ruleSetId = RuleSet::query()->activeForStore($storeId)->value('id');

        if ($ruleSetId === null) {
            return new Collection;
        }

        return AssociationRule::query()
            ->where('rule_set_id', $ruleSetId)
            ->where('store_id', $storeId)
            ->where('antecedent_product_id', $product->getKey())
            ->where('consequent_product_id', '!=', $product->getKey())
            ->whereHas('consequentProduct', function (Builder $query) use ($storeId): void {
                $query->where('store_id', $storeId)->where('status', Product::STATUS_ACTIVE);
            })
            ->with('consequentProduct')
            ->orderByDesc('confidence')
            ->orderByDesc('lift')
            ->orderByDesc('pair_count')
            ->orderBy('consequent_product_id')
            ->limit($limit)
            ->get();
    }

    public function hasActiveModel(Store $store): bool
    {
        return RuleSet::query()->activeForStore((int) $store->getKey())->exists();
    }

    /**
     * Recommendations for the products currently in a basket. IDs that are
     * not active products of the store are ignored as antecedents and are
     * still never recommended back.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, AssociationRule> the best rule per recommended product, with `antecedentProduct` and `consequentProduct` loaded
     */
    public function forBasket(Store $store, array $productIds, int $limit = self::BASKET_LIMIT): Collection
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('The limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if (count($productIds) > self::MAX_BASKET_PRODUCTS) {
            throw new InvalidArgumentException('A basket can hold at most '.self::MAX_BASKET_PRODUCTS.' products.');
        }

        $storeId = (int) $store->getKey();
        $ruleSetId = $productIds === [] ? null : RuleSet::query()->activeForStore($storeId)->value('id');

        if ($ruleSetId === null) {
            return new Collection;
        }

        $antecedentIds = Product::query()
            ->where('store_id', $storeId)
            ->where('status', Product::STATUS_ACTIVE)
            ->whereKey($productIds)
            ->pluck('id')
            ->all();

        if ($antecedentIds === []) {
            return new Collection;
        }

        $rules = AssociationRule::query()
            ->where('rule_set_id', $ruleSetId)
            ->where('store_id', $storeId)
            ->whereIn('antecedent_product_id', $antecedentIds)
            ->whereNotIn('consequent_product_id', $productIds)
            ->whereHas('consequentProduct', function (Builder $query) use ($storeId): void {
                $query->where('store_id', $storeId)->where('status', Product::STATUS_ACTIVE);
            })
            ->orderByDesc('confidence')
            ->orderByDesc('lift')
            ->orderByDesc('pair_count')
            ->orderBy('consequent_product_id')
            ->orderBy('antecedent_product_id')
            ->cursor();

        // Rules arrive best first, so a product's first rule is its strongest one.
        $best = [];

        foreach ($rules as $rule) {
            $best[$rule->consequent_product_id] ??= $rule;

            if (count($best) === $limit) {
                break;
            }
        }

        return (new Collection(array_values($best)))->load([
            'antecedentProduct:id,name',
            'consequentProduct:id,name,unit_price',
        ]);
    }
}
