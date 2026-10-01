<?php

use App\Models\Customer;
use Illuminate\Database\QueryException;

it('requires customer email at the database level', function () {
    expect(fn () => Customer::factory()->create(['email' => null]))
        ->toThrow(QueryException::class);
});

it('enforces unique customer email at the database level', function () {
    Customer::factory()->create(['email' => 'same@example.test']);

    expect(fn () => Customer::factory()->create(['email' => 'same@example.test']))
        ->toThrow(QueryException::class);
});
