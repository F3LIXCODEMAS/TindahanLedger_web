<?php

use App\Models\Customer;
use Illuminate\Database\QueryException;

it('allows a customer email to be missing at the database level', function () {
    $customer = Customer::factory()->create(['email' => null]);

    expect($customer->fresh()->email)->toBeNull();
});

it('enforces unique customer email at the database level', function () {
    Customer::factory()->create(['email' => 'same@example.test']);

    expect(fn () => Customer::factory()->create(['email' => 'same@example.test']))
        ->toThrow(QueryException::class);
});
