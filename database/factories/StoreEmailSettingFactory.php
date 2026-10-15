<?php

namespace Database\Factories;

use App\Models\StoreEmailSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreEmailSetting>
 */
class StoreEmailSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_name' => fake()->company(),
            'sender_email' => fake()->unique()->safeEmail(),
            'smtp_app_password' => fake()->password(16, 32),
        ];
    }
}
