<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPointTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SukiPointTransaction>
 */
class SukiPointTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $customer = Customer::factory();
        $ledgerEntry = LedgerEntry::factory()->for($customer);

        return [
            'customer_id' => $customer,
            'payment_id' => Payment::factory()
                ->for($customer)
                ->for($ledgerEntry, 'ledgerEntry'),
            'paid_amount_cents' => 10000,
            'points_awarded' => 1,
            'remainder_before_cents' => 0,
            'remainder_after_cents' => 0,
        ];
    }
}
