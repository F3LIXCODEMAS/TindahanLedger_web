<?php

use App\Exceptions\StoreSuspended;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Services\LedgerService;
use Illuminate\Support\Str;

it('keeps a suspended store readable by its owner', function () {
    $store = Store::factory()->suspended()->create();
    $customer = Customer::factory()->for($store)->create(['full_name' => 'Readable Customer']);

    $this->actingAs($store->owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('This store is suspended.');
    $this->get(route('customers.index'))->assertOk()->assertSee('Readable Customer');
    $this->get(route('customers.show', $customer))->assertOk()->assertSee('Readable Customer');
});

it('blocks store operations for a suspended store without writing records', function (string $method, Closure $url, Closure $payload) {
    $store = Store::factory()->suspended()->create();
    $customer = Customer::factory()->for($store)->create(['full_name' => 'Original Name', 'credit_limit' => '5000.00']);
    LedgerEntry::factory()->for($customer)->create(['amount' => '300.00']);

    $this->actingAs($store->owner)
        ->call($method, $url($customer), $payload($customer))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('store');

    expect(Customer::query()->count())->toBe(1);
    expect($customer->fresh()->full_name)->toBe('Original Name');
    expect(LedgerEntry::query()->count())->toBe(1);
    expect(Payment::query()->count())->toBe(0);
})->with([
    'open record credit form' => ['GET', fn () => route('credits.create'), fn () => []],
    'record credit' => ['POST', fn () => route('credits.store'), fn (Customer $customer) => [
        'customer_id' => $customer->id,
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ]],
    'open record payment form' => ['GET', fn () => route('payments.create'), fn () => []],
    'record payment' => ['POST', fn () => route('payments.store'), fn (Customer $customer) => [
        'customer_id' => $customer->id,
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ]],
    'open add customer form' => ['GET', fn () => route('customers.create'), fn () => []],
    'add customer' => ['POST', fn () => route('customers.store'), fn () => [
        'full_name' => 'New Customer',
        'contact_number' => '09181234567',
        'residential_landmark' => 'Near the market',
        'credit_limit' => '500.00',
    ]],
    'open edit customer form' => ['GET', fn (Customer $customer) => route('customers.edit', $customer), fn () => []],
    'update customer' => ['PUT', fn (Customer $customer) => route('customers.update', $customer), fn () => [
        'full_name' => 'Changed Name',
        'contact_number' => '09181234567',
        'residential_landmark' => 'Near the market',
        'credit_limit' => '5000.00',
    ]],
]);

it('rejects ledger writes in the service once the store is suspended', function (Closure $recordTransaction) {
    $store = Store::factory()->suspended()->create();
    $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
    $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '300.00']);

    expect(fn () => $recordTransaction(app(LedgerService::class), $customer, $entry))
        ->toThrow(StoreSuspended::class);

    expect(LedgerEntry::query()->count())->toBe(1);
    expect(Payment::query()->count())->toBe(0);
})->with([
    'credit' => fn (LedgerService $ledgerService, Customer $customer) => $ledgerService->recordCredit($customer, ['amount' => '100.00']),
    'customer payment' => fn (LedgerService $ledgerService, Customer $customer) => $ledgerService->recordCustomerPayment($customer, [
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ]),
    'entry payment' => fn (LedgerService $ledgerService, Customer $customer, LedgerEntry $entry) => $ledgerService->recordPayment($entry, '100.00'),
]);

it('allows recording credit again after the store is reactivated', function () {
    $store = Store::factory()->suspended()->create();
    $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

    $store->forceFill(['status' => Store::STATUS_ACTIVE])->save();

    $this->actingAs($store->owner)->post(route('credits.store'), [
        'customer_id' => $customer->id,
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ])->assertSessionHasNoErrors();

    expect($customer->ledgerEntries()->count())->toBe(1);
});
