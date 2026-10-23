<?php

namespace App\Services;

use App\Exceptions\CashPurchaseRejected;
use App\Exceptions\StoreSuspended;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records cash purchases (DUMS-37): products paid for on the spot.
 *
 * A cash purchase is a sale, not credit and not a repayment. This service
 * never creates ledger entries or payments and never touches balances,
 * debt cycles, late fees, or Suki Points. It writes only `purchases` and
 * `purchase_items`.
 */
class CashPurchaseService
{
    public const MAX_ITEMS = LedgerService::MAX_PURCHASE_ITEMS;

    public const UNAVAILABLE_PRODUCTS_MESSAGE = 'One or more selected products are not available in your active catalog. Remove them and try again.';

    /**
     * Each item may carry a sale-only `unit_price`; when it is omitted the
     * product's catalog price is used. `expected_total` is the total the
     * owner reviewed; the sale is rejected if the server's total differs.
     *
     * The store row is locked for update, so a suspension waits for this
     * sale and two submissions with the same idempotency key run one after
     * the other: the second finds and returns the first.
     *
     * @param  array{idempotency_key: string, customer_id?: int|null, items: list<array{product_id: int|string, quantity: int|string, unit_price?: string|null}>, expected_total: string}  $attributes
     *
     * @throws CashPurchaseRejected
     * @throws StoreSuspended
     */
    public function record(Store $store, User $recordedBy, array $attributes): Purchase
    {
        return DB::transaction(function () use ($store, $recordedBy, $attributes): Purchase {
            $storeStatus = Store::query()->whereKey($store->getKey())->lockForUpdate()->value('status');

            if ($storeStatus !== Store::STATUS_ACTIVE) {
                throw new StoreSuspended;
            }

            $existingPurchase = Purchase::query()
                ->where('store_id', $store->getKey())
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if ($existingPurchase !== null) {
                return $existingPurchase;
            }

            $customerId = $this->authorizedCustomerId($store, $attributes['customer_id'] ?? null);
            $lines = $this->prepareLines($store, $attributes['items']);
            $totalInCents = array_sum(array_column($lines, 'line_total_cents'));

            if ($totalInCents === 0) {
                throw new CashPurchaseRejected('items', 'The cash purchase total must be greater than zero.');
            }

            if ($this->expectedTotalInCents($attributes['expected_total']) !== $totalInCents) {
                throw new CashPurchaseRejected(
                    'expected_total',
                    'The total is now ₱'.Money::fromCents($totalInCents).'. Review the items and prices, then record the purchase again.',
                );
            }

            $purchase = new Purchase;
            $purchase->forceFill([
                'store_id' => $store->getKey(),
                'customer_id' => $customerId,
                'ledger_entry_id' => null,
                'purchase_type' => Purchase::TYPE_CASH,
                'purchase_date' => now()->toDateString(),
                'recorded_by_user_id' => $recordedBy->getKey(),
                'idempotency_key' => $attributes['idempotency_key'],
            ])->save();

            foreach ($lines as $line) {
                unset($line['line_total_cents']);
                $purchase->items()->forceCreate([...$line, 'store_id' => $store->getKey()]);
            }

            return $purchase;
        });
    }

    private function authorizedCustomerId(Store $store, mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $customer = filter_var($customerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ? null
            : Customer::query()->where('store_id', $store->getKey())->whereKey((int) $customerId)->first();

        if ($customer === null) {
            throw new CashPurchaseRejected('customer_id', 'Choose a customer from your store, or leave it empty for a walk-in sale.');
        }

        return $customer->getKey();
    }

    /**
     * Validate items against the store's active catalog (read with a shared
     * lock so a concurrent deactivation waits) and price each line.
     *
     * @param  array<int, mixed>  $items
     * @return list<array{product_id: int, product_name_snapshot: string, quantity: string, unit_price_snapshot: string, line_total: string, line_total_cents: int}>
     */
    private function prepareLines(Store $store, array $items): array
    {
        if ($items === []) {
            throw new CashPurchaseRejected('items', 'Add at least one product to the cash purchase.');
        }

        if (count($items) > self::MAX_ITEMS) {
            throw new CashPurchaseRejected('items', 'A cash purchase can list at most '.self::MAX_ITEMS.' products.');
        }

        $submittedItems = [];

        foreach ($items as $item) {
            $productId = is_array($item) ? filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            if ($productId === false) {
                throw new CashPurchaseRejected('items', self::UNAVAILABLE_PRODUCTS_MESSAGE);
            }

            if (array_key_exists($productId, $submittedItems)) {
                throw new CashPurchaseRejected('items', 'Each product can only be added once.');
            }

            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;

            try {
                $submittedItems[$productId] = [
                    'quantity_in_thousandths' => Quantity::toThousandths(is_int($quantity) || is_string($quantity) ? $quantity : ''),
                    'unit_price_in_cents' => $unitPrice === null || $unitPrice === '' ? null : Money::toCents(is_string($unitPrice) ? $unitPrice : ''),
                ];
            } catch (InvalidArgumentException $exception) {
                throw new CashPurchaseRejected('items', $exception->getMessage());
            }
        }

        $products = Product::query()
            ->where('store_id', $store->getKey())
            ->whereKey(array_keys($submittedItems))
            ->sharedLock()
            ->get()
            ->keyBy('id');
        $lines = [];

        foreach ($submittedItems as $productId => $submittedItem) {
            $product = $products->get($productId);

            if ($product === null || ! $product->isActive()) {
                throw new CashPurchaseRejected('items', self::UNAVAILABLE_PRODUCTS_MESSAGE);
            }

            $unitPriceInCents = $submittedItem['unit_price_in_cents']
                ?? ($product->unit_price === null ? null : Money::toCents($product->unit_price));

            if ($unitPriceInCents === null) {
                throw new CashPurchaseRejected('items', "Enter a price for {$product->name}; it has no catalog price.");
            }

            $lineTotalInCents = Money::lineTotalCents($unitPriceInCents, $submittedItem['quantity_in_thousandths']);
            $lines[] = [
                'product_id' => $product->getKey(),
                'product_name_snapshot' => $product->name,
                'quantity' => Quantity::fromThousandths($submittedItem['quantity_in_thousandths']),
                'unit_price_snapshot' => Money::fromCents($unitPriceInCents),
                'line_total' => Money::fromCents($lineTotalInCents),
                'line_total_cents' => $lineTotalInCents,
            ];
        }

        return $lines;
    }

    private function expectedTotalInCents(string $expectedTotal): ?int
    {
        try {
            return Money::toCents($expectedTotal);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
