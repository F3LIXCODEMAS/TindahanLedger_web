<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SukiPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SukiPoint>
 */
class SukiPointFactory extends Factory
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
            'completed_cycles' => 0,
            'current_cycle_count' => 0,
            'reward_threshold' => 3,
            'points_balance' => 0,
            'reward_eligible' => false,
        ];
    }
}
