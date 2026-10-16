<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAccessCredential;
use App\Services\CustomerAccessService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAccessCredential>
 */
class CustomerAccessCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = CustomerAccessService::generateToken();

        return [
            'customer_id' => Customer::factory(),
            'active_customer_id' => fn (array $attributes) => $attributes['customer_id'],
            'token_hash' => CustomerAccessCredential::hashToken($token),
            'encrypted_token' => $token,
            'status' => CustomerAccessCredential::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the credential has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_customer_id' => null,
            'encrypted_token' => null,
            'status' => CustomerAccessCredential::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
