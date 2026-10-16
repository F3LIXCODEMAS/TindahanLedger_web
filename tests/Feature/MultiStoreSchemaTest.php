<?php

use App\Models\Customer;
use App\Models\EmailReminderLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;

it('rejects a payment whose ledger entry belongs to another customer', function () {
    $ledgerEntry = LedgerEntry::factory()->create();
    $otherCustomer = Customer::factory()->create();

    expect(fn () => Payment::factory()->create([
        'ledger_entry_id' => $ledgerEntry->id,
        'customer_id' => $otherCustomer->id,
    ]))->toThrow(QueryException::class);

    expect(Payment::query()->count())->toBe(0);
});

it('rejects a reminder log whose ledger entry belongs to another customer', function () {
    $ledgerEntry = LedgerEntry::factory()->create();
    $otherCustomer = Customer::factory()->create();

    expect(fn () => EmailReminderLog::factory()->create([
        'ledger_entry_id' => $ledgerEntry->id,
        'customer_id' => $otherCustomer->id,
    ]))->toThrow(QueryException::class);

    expect(EmailReminderLog::query()->count())->toBe(0);
});

it('prevents deleting a store that still has customers', function () {
    $customer = Customer::factory()->create();

    expect(fn () => $customer->store->delete())->toThrow(QueryException::class);

    $this->assertModelExists($customer->store);
    $this->assertModelExists($customer);
});

it('prevents deleting an owner account that still owns a store', function () {
    $owner = User::factory()->withStore()->create();

    expect(fn () => $owner->delete())->toThrow(QueryException::class);

    $this->assertModelExists($owner);
    $this->assertModelExists($owner->store);
});

it('rejects assigning one owner account to two stores', function () {
    $owner = User::factory()->withStore()->create();

    expect(fn () => Store::factory()->for($owner, 'owner')->create())
        ->toThrow(QueryException::class);
});

it('does not mass assign role or status to a user', function () {
    $user = User::query()->create([
        'name' => 'Attacker',
        'username' => 'attacker',
        'email' => 'attacker@example.test',
        'password' => 'a-strong-password-123',
        'role' => User::ROLE_SUPER_ADMIN,
        'status' => User::STATUS_INACTIVE,
    ]);

    expect($user->fresh()->role)->toBe(User::ROLE_STORE_OWNER);
    expect($user->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('stores usernames trimmed with single spaces and in lowercase', function () {
    $user = User::factory()->create(['username' => '  Mang   Arthur Store ']);

    expect($user->fresh()->username)->toBe('mang arthur store');
});

it('rejects a username that differs from an existing one only by case or spacing', function () {
    User::factory()->create(['username' => 'Mang Arthur Store']);

    expect(fn () => User::factory()->create(['username' => ' MANG  arthur store']))
        ->toThrow(QueryException::class);
});
