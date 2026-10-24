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
 * The caller is responsible for authorizing the user for the store.
 */
class ProductRecommendationService
{
    public const MAX_LIMIT = 50;

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
}
