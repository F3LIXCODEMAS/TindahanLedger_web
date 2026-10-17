<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtCycleCompletion>
 */
class DebtCycleCompletionFactory extends Factory
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
            'completing_payment_id' => null,
            'cycle_number' => 1,
            'streak_position' => 1,
            'repaid_cents' => 50000,
            'reward_points' => 0,
            'source' => DebtCycleCompletion::SOURCE_PAYMENT,
            'completed_at' => now(),
        ];
    }
}
