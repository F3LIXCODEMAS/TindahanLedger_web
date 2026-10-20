<?php

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\QueryException;

it('allows a customer email to be missing at the database level', function () {
    $customer = Customer::factory()->create(['email' => null]);

    expect($customer->fresh()->email)->toBeNull();
});

it('enforces unique customer email within a store at the database level', function () {
    $store = Store::factory()->create();
    Customer::factory()->for($store)->create(['email' => 'same@example.test']);

    expect(fn () => Customer::factory()->for($store)->create(['email' => 'same@example.test']))
        ->toThrow(QueryException::class);
});

it('allows different stores to keep a customer with the same email', function () {
    Customer::factory()->create(['email' => 'same@example.test']);
    Customer::factory()->create(['email' => 'same@example.test']);

    expect(Customer::query()->where('email', 'same@example.test')->count())->toBe(2);
});
