<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorePenaltyPolicy>
 */
class StorePenaltyPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'is_enabled' => true,
            'fee_cents' => 1000,
            'grace_days' => 3,
            'disclosure_text' => null,
            'effective_from' => now()->toDateString(),
            'version' => 1,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['is_enabled' => false]);
    }
}
