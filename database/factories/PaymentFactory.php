<?php

namespace Database\Factories;

use App\Models\Customer;
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
            'customer_id' => Customer::factory(),
            'ledger_entry_id' => LedgerEntry::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'today')->format('Y-m-d'),
        ];
    }
}
