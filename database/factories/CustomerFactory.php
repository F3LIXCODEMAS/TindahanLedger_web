<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
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
            'full_name' => fake()->name(),
            'contact_number' => fake()->numerify('09#########'),
            'email' => fake()->unique()->userName().'@example.test',
            'residential_landmark' => fake()->streetName().' near '.fake()->city(),
            'credit_limit' => fake()->randomElement([3000, 5000, 8000, 10000]),
            'current_balance' => 0,
        ];
    }
}
