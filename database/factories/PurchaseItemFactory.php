<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The store follows the purchase and the product is created in that store,
 * so factory-built items satisfy the same-store foreign keys.
 *
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'store_id' => fn (array $attributes): int => Purchase::query()->findOrFail($attributes['purchase_id'])->store_id,
            'product_id' => fn (array $attributes) => Product::factory()->for(Store::query()->findOrFail($attributes['store_id'])),
            'product_name_snapshot' => fn (array $attributes): string => Product::query()->findOrFail($attributes['product_id'])->name,
            'quantity' => '1.000',
            'unit_price_snapshot' => null,
            'line_total' => null,
        ];
    }
}
