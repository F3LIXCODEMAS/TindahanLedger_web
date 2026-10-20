<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ledger_entry_id' => LedgerEntry::factory(),
            'customer_id' => fn (array $attributes): int => LedgerEntry::query()
                ->whereKey($attributes['ledger_entry_id'])
                ->value('customer_id'),
            'amount' => fake()->randomFloat(2, 10, 500),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'today')->format('Y-m-d'),
        ];
    }
}
