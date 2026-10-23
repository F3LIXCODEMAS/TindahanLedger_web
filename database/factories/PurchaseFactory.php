<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The customer and store follow the ledger entry, so factory-built
 * purchases satisfy the same-store foreign keys.
 *
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ledger_entry_id' => LedgerEntry::factory(),
            'customer_id' => fn (array $attributes): int => LedgerEntry::query()->findOrFail($attributes['ledger_entry_id'])->customer_id,
            'store_id' => fn (array $attributes): int => Customer::query()->findOrFail($attributes['customer_id'])->store_id,
            'purchase_type' => Purchase::TYPE_UTANG,
            'purchase_date' => fn (array $attributes): string => LedgerEntry::query()->findOrFail($attributes['ledger_entry_id'])->transaction_date->toDateString(),
            'recorded_by_user_id' => null,
        ];
    }

    /**
     * A walk-in cash sale: no ledger entry and no customer.
     */
    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => Store::factory(),
            'customer_id' => null,
            'ledger_entry_id' => null,
            'purchase_type' => Purchase::TYPE_CASH,
            'purchase_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
