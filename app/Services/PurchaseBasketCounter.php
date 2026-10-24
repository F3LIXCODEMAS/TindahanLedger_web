<?php

namespace App\Services;

use App\Models\Purchase;
use App\Support\BasketCounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Counts one store's purchase baskets for association rules (DUMS-39).
 * Read-only: it selects purchase and product IDs only, never customer,
 * ledger, payment, or loyalty data.
 *
 * A basket is one purchase. An item is eligible when:
 * - the purchase and the item belong to the requested store;
 * - the purchase is a complete utang credit (linked to its ledger entry)
 *   or a cash sale (no ledger entry). Payments are never baskets, and an
 *   utang purchase without its ledger entry is treated as incomplete;
 * - the product resolves to a catalog product of the same store (inactive
 *   products still count: they were really bought);
 * - the quantity is above zero.
 *
 * All counts, and the cutoff (the newest purchase ID they include), are
 * read in one transaction. On MySQL/MariaDB it uses REPEATABLE READ, so
 * every query sees the same snapshot: a purchase committed by another
 * request while counting runs cannot appear in some counts but not
 * others. When the caller is already inside a transaction, that
 * transaction's isolation applies instead.
 *
 * The schema has no draft, cancelled, or voided purchase states, so none
 * can be excluded. A purchase with no eligible item is not a basket.
 * Products are counted once per basket even if listed twice.
 */
class PurchaseBasketCounter
{
    /**
     * @return array{counts: BasketCounts, cutoff_purchase_id: int}
     */
    public function count(int $storeId, int $minPairCount): array
    {
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('set transaction isolation level repeatable read');
        }

        return DB::transaction(function () use ($storeId, $minPairCount): array {
            $cutoffPurchaseId = (int) Purchase::query()->where('store_id', $storeId)->max('id');

            return [
                'counts' => $this->countUpTo($storeId, $cutoffPurchaseId, $minPairCount),
                'cutoff_purchase_id' => $cutoffPurchaseId,
            ];
        });
    }

    private function countUpTo(int $storeId, int $cutoffPurchaseId, int $minPairCount): BasketCounts
    {
        $eligible = $this->eligibleItems($storeId, $cutoffPurchaseId)
            ->select('purchase_items.purchase_id', 'purchase_items.product_id')
            ->distinct();

        $basketCount = $this->eligibleItems($storeId, $cutoffPurchaseId)
            ->distinct()
            ->count('purchase_items.purchase_id');

        $itemCounts = DB::query()->fromSub($eligible, 'basket_items')
            ->select('product_id', DB::raw('count(*) as basket_count'))
            ->groupBy('product_id')
            ->orderBy('product_id')
            ->pluck('basket_count', 'product_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $pairCounts = DB::query()->fromSub($eligible, 'first_items')
            ->joinSub($eligible, 'second_items', function (JoinClause $join): void {
                $join->on('second_items.purchase_id', '=', 'first_items.purchase_id')
                    ->whereColumn('second_items.product_id', '>', 'first_items.product_id');
            })
            ->select('first_items.product_id as first_product_id', 'second_items.product_id as second_product_id', DB::raw('count(*) as pair_count'))
            ->groupBy('first_items.product_id', 'second_items.product_id')
            ->havingRaw('count(*) >= ?', [$minPairCount])
            ->orderBy('first_items.product_id')
            ->orderBy('second_items.product_id')
            ->get()
            ->map(fn (object $pair): array => [(int) $pair->first_product_id, (int) $pair->second_product_id, (int) $pair->pair_count])
            ->all();

        return new BasketCounts((int) $basketCount, $itemCounts, $pairCounts);
    }

    private function eligibleItems(int $storeId, int $cutoffPurchaseId): Builder
    {
        return DB::table('purchase_items')
            ->join('purchases', function (JoinClause $join): void {
                $join->on('purchases.id', '=', 'purchase_items.purchase_id')
                    ->on('purchases.store_id', '=', 'purchase_items.store_id');
            })
            ->join('products', function (JoinClause $join): void {
                $join->on('products.id', '=', 'purchase_items.product_id')
                    ->on('products.store_id', '=', 'purchase_items.store_id');
            })
            ->where('purchase_items.store_id', $storeId)
            ->where('purchases.store_id', $storeId)
            ->where('purchases.id', '<=', $cutoffPurchaseId)
            ->where('purchase_items.quantity', '>', 0)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $utang): void {
                    $utang->where('purchases.purchase_type', Purchase::TYPE_UTANG)
                        ->whereNotNull('purchases.ledger_entry_id')
                        ->whereNotNull('purchases.customer_id');
                })->orWhere(function (Builder $cash): void {
                    $cash->where('purchases.purchase_type', Purchase::TYPE_CASH)
                        ->whereNull('purchases.ledger_entry_id');
                });
            });
    }
}
