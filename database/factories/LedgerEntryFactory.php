<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 100, 2000),
            'itemized_list' => [fake()->words(2, true)],
            'transaction_date' => fake()->dateTimeBetween('-60 days', 'today')->format('Y-m-d'),
            'repayment_deadline' => fake()->dateTimeBetween('today', '+30 days')->format('Y-m-d'),
            'running_balance' => 0,
        ];
    }
}
