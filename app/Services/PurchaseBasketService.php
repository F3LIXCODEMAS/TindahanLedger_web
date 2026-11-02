<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PurchaseBasketService
{
    /**
     * Extract purchase baskets for one store.
     *
     * Each basket represents one eligible credit transaction.
     */
    public function getBaskets(Store $store): Collection
    {
        $entries = LedgerEntry::query()
            ->credits()
            ->whereHas('customer', function ($query) use ($store) {
                $query->where('store_id', $store->id);
            })
            ->with('customer:id,full_name,store_id')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        return $entries
            ->map(function (LedgerEntry $entry) {
                $products = $this->extractProducts(
                    $entry->itemized_list
                );

                // Ignore transactions without valid product descriptions.
                if (empty($products)) {
                    return null;
                }

                return [
                    'transaction_id' => $entry->id,
                    'customer_id' => $entry->customer_id,
                    'customer_name' => $entry->customer?->full_name,
                    'transaction_date' => $entry->transaction_date,
                    'products' => $products,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Convert the transaction's itemized description into product names.
     */
    private function extractProducts(?array $itemizedList): array
    {
        if (empty($itemizedList)) {
            return [];
        }

        $products = [];

        foreach ($itemizedList as $description) {
            if (!is_string($description)) {
                continue;
            }

            // Split descriptions separated by commas, semicolons, or new lines.
            $items = preg_split('/[,;\r\n]+/u', $description);

            foreach ($items as $item) {
                $item = Str::squish($item);

                if ($item === '') {
                    continue;
                }

                // Use lowercase for duplicate detection.
                $normalized = mb_strtolower($item);

                $products[$normalized] = $normalized;
            }
        }

        return array_values($products);
    }
}
