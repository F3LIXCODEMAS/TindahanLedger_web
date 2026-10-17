<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

it('responds as not found when an owner opens another store record by id', function (Closure $otherStoreUrl) {
    $owner = User::factory()->withStore()->create();
    $otherCustomer = Customer::factory()->create();
    $otherEntry = LedgerEntry::factory()->for($otherCustomer)->create();
    $otherBatchId = Str::uuid()->toString();
    Payment::factory()->for($otherCustomer)->for($otherEntry)->create(['payment_batch_id' => $otherBatchId]);

    $this->actingAs($owner)
        ->get($otherStoreUrl($otherCustomer, $otherEntry, $otherBatchId))
        ->assertNotFound()
        ->assertDontSee($otherCustomer->full_name);
})->with([
    'customer ledger' => fn (Customer $customer) => route('customers.show', $customer),
    'customer edit form' => fn (Customer $customer) => route('customers.edit', $customer),
    'credit form summary' => fn (Customer $customer) => route('credits.customer-summary', $customer),
    'payment form summary' => fn (Customer $customer) => route('payments.customer-summary', $customer),
    'credit confirmation' => fn (Customer $customer, LedgerEntry $entry) => route('credits.confirmation', $entry),
    'payment confirmation' => fn (Customer $customer, LedgerEntry $entry, string $batchId) => route('payments.confirmation', $batchId),
]);

it('shows the same not-found page for another store customer and a missing customer', function () {
    $owner = User::factory()->withStore()->create();
    $otherCustomer = Customer::factory()->create();

    $crossStoreResponse = $this->actingAs($owner)->get(route('customers.show', $otherCustomer));
    $missingResponse = $this->actingAs($owner)->get(route('customers.show', $otherCustomer->id + 1000));

    $crossStoreResponse->assertNotFound();
    $missingResponse->assertNotFound();
    expect($crossStoreResponse->getContent())->toBe($missingResponse->getContent());
});

it('lists only the owner store customers on every customer listing', function (string $routeName) {
    $owner = User::factory()->withStore()->create();
    $ownCustomer = Customer::factory()->for($owner->store)->create(['full_name' => 'Own Store Customer']);
    $otherCustomer = Customer::factory()->create(['full_name' => 'Other Store Customer']);
    LedgerEntry::factory()->for($ownCustomer)->create(['repayment_deadline' => now()->subDays(3)->toDateString()]);
    LedgerEntry::factory()->for($otherCustomer)->create(['repayment_deadline' => now()->subDays(3)->toDateString()]);

    $this->actingAs($owner)
        ->get(route($routeName))
        ->assertOk()
        ->assertSee('Own Store Customer')
        ->assertDontSee('Other Store Customer');
})->with([
    'customer list' => 'customers.index',
    'record credit form' => 'credits.create',
    'record payment form' => 'payments.create',
    'overdue accounts' => 'overdue-accounts.index',
    'dashboard activity' => 'dashboard',
]);

it('totals dashboard collectibles from the owner store only', function () {
    $owner = User::factory()->withStore()->create();
    $ownCustomer = Customer::factory()->for($owner->store)->create();
    LedgerEntry::factory()->for($ownCustomer)->create(['amount' => '150.00']);
    LedgerEntry::factory()->for(Customer::factory())->create(['amount' => '9999.00']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('outstandingPrincipal', '150.00');
});

it('rejects updating another store customer without changing it', function () {
    $owner = User::factory()->withStore()->create();
    $otherCustomer = Customer::factory()->create(['full_name' => 'Original Name']);

    $this->actingAs($owner)->put(route('customers.update', $otherCustomer), [
        'full_name' => 'Hijacked Name',
        'contact_number' => '09171234567',
        'residential_landmark' => 'Near the market',
        'credit_limit' => '500.00',
    ])->assertNotFound();

    expect($otherCustomer->fresh()->full_name)->toBe('Original Name');
});

it('rejects recording a credit for another store customer', function () {
    $owner = User::factory()->withStore()->create();
    $otherCustomer = Customer::factory()->create(['credit_limit' => '5000.00']);

    $this->actingAs($owner)->from(route('credits.create'))->post(route('credits.store'), [
        'customer_id' => $otherCustomer->id,
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ])->assertRedirect(route('credits.create'))
        ->assertInvalid(['customer_id']);

    expect(LedgerEntry::query()->count())->toBe(0);
});

it('rejects recording a payment for another store customer', function () {
    $owner = User::factory()->withStore()->create();
    $otherCustomer = Customer::factory()->create();
    LedgerEntry::factory()->for($otherCustomer)->create(['amount' => '300.00']);

    $this->actingAs($owner)->from(route('payments.create'))->post(route('payments.store'), [
        'customer_id' => $otherCustomer->id,
        'amount' => '100.00',
        'idempotency_key' => Str::uuid()->toString(),
    ])->assertRedirect(route('payments.create'))
        ->assertInvalid(['customer_id']);

    expect(Payment::query()->count())->toBe(0);
});

it('allows another store to register a customer with the same name contact and email', function () {
    $owner = User::factory()->withStore()->create();
    Customer::factory()->for(Store::factory())->create([
        'full_name' => 'Maria Santos',
        'contact_number' => '+639171234567',
        'email' => 'maria@example.test',
    ]);

    $this->actingAs($owner)->post(route('customers.store'), [
        'full_name' => 'Maria Santos',
        'contact_number' => '09171234567',
        'email' => 'maria@example.test',
        'residential_landmark' => 'Near the market',
        'credit_limit' => '500.00',
    ])->assertRedirect(route('customers.index'))
        ->assertSessionHasNoErrors();

    expect($owner->store->customers()->where('email', 'maria@example.test')->exists())->toBeTrue();
});
