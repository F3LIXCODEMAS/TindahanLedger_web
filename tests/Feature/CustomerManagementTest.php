<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Mail;

it('redirects guests away from the customer list and forms', function () {
    $customer = Customer::factory()->create();

    $this->get(route('customers.index'))->assertRedirect(route('login'));
    $this->get(route('customers.create'))->assertRedirect(route('login'));
    $this->get(route('customers.edit', $customer))->assertRedirect(route('login'));
});

it('lets the owner open the customer list and add form', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('customers.index'))->assertOk()->assertSee('Customers');
    $this->get(route('customers.create'))->assertOk()->assertSee('Add customer');
});

it('creates a customer with normalized name phone and email without sending mail', function () {
    Mail::fake();
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->post(route('customers.store'), [
        'full_name' => '  Maria   Santos  ',
        'contact_number' => '0917-123-4567',
        'email' => '  MARIA.SANTOS@Example.Test ',
        'residential_landmark' => '  Near the public market  ',
        'credit_limit' => '500.00',
    ]);

    $response->assertRedirect(route('customers.index'))
        ->assertSessionHas('status', 'Customer added successfully.');

    $customer = Customer::query()->where('email', 'maria.santos@example.test')->firstOrFail();
    expect($customer->full_name)->toBe('Maria Santos');
    expect($customer->contact_number)->toBe('+639171234567');
    expect($customer->residential_landmark)->toBe('Near the public market');
    expect($customer->credit_limit)->toBe('500.00');
    Mail::assertNothingOutgoing();
});

it('rejects missing fields invalid phone email and credit limit', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $this->from(route('customers.create'))->post(route('customers.store'), [])
        ->assertInvalid(['full_name', 'contact_number', 'email', 'residential_landmark', 'credit_limit']);

    $this->from(route('customers.create'))->post(route('customers.store'), [
        'full_name' => 'Test Customer',
        'contact_number' => '555-1212',
        'email' => 'not-an-email',
        'residential_landmark' => 'Near shop',
        'credit_limit' => '0',
    ])->assertInvalid(['contact_number', 'email', 'credit_limit']);

    expect(Customer::query()->count())->toBe(0);
});

it('rejects duplicate normalized name and contact number separately from email', function () {
    $owner = User::factory()->create();
    Customer::factory()->create([
        'full_name' => 'Maria Santos',
        'contact_number' => '+639171234567',
        'email' => 'first@example.test',
    ]);

    $this->actingAs($owner)->from(route('customers.create'))->post(route('customers.store'), [
        'full_name' => '  MARIA   SANTOS ',
        'contact_number' => '0917 123 4567',
        'email' => 'second@example.test',
        'residential_landmark' => 'Another landmark',
        'credit_limit' => '600.00',
    ])->assertInvalid(['contact_number' => 'A customer with this name and contact number already exists.']);

    expect(Customer::query()->count())->toBe(1);
});

it('rejects duplicate email addresses during customer creation', function () {
    $owner = User::factory()->create();
    Customer::factory()->create(['email' => 'first@example.test']);

    $this->actingAs($owner)->from(route('customers.create'))->post(route('customers.store'), [
        'full_name' => 'Different Customer',
        'contact_number' => '09221234567',
        'email' => ' FIRST@example.test ',
        'residential_landmark' => 'Near the school',
        'credit_limit' => '900.00',
    ])->assertInvalid(['email' => 'This email address is already registered to another customer.']);

    expect(Customer::query()->count())->toBe(1);
});

it('edits a customer while allowing its unchanged email and normalized identity', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create([
        'full_name' => 'Maria Santos',
        'contact_number' => '+639171234567',
        'email' => 'maria@example.test',
    ]);

    $this->actingAs($owner)->get(route('customers.edit', $customer))
        ->assertOk()
        ->assertSee('maria@example.test');

    $this->put(route('customers.update', $customer), [
        'full_name' => ' Maria  Santos ',
        'contact_number' => '0917-123-4567',
        'email' => 'MARIA@example.test',
        'residential_landmark' => 'Updated landmark',
        'credit_limit' => '1200.00',
    ])->assertRedirect(route('customers.index'))
        ->assertSessionHas('status', 'Customer updated successfully.');

    expect($customer->fresh()->full_name)->toBe('Maria Santos');
    expect($customer->fresh()->contact_number)->toBe('+639171234567');
    expect($customer->fresh()->email)->toBe('maria@example.test');
});

it('rejects another customer email and normalized duplicate during customer edit', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create([
        'full_name' => 'Maria Santos',
        'contact_number' => '+639171234567',
        'email' => 'maria@example.test',
    ]);
    Customer::factory()->create([
        'full_name' => 'Jose Reyes',
        'contact_number' => '+639181234567',
        'email' => 'jose@example.test',
    ]);
    $this->actingAs($owner);

    $this->put(route('customers.update', $customer), [
        'full_name' => 'Maria Santos',
        'contact_number' => '+639171234567',
        'email' => 'jose@example.test',
        'residential_landmark' => 'Near store',
        'credit_limit' => '1000.00',
    ])->assertInvalid(['email']);

    $this->put(route('customers.update', $customer), [
        'full_name' => 'Jose Reyes',
        'contact_number' => '0918-123-4567',
        'email' => 'new@example.test',
        'residential_landmark' => 'Near store',
        'credit_limit' => '1000.00',
    ])->assertInvalid(['contact_number']);
});

it('does not lower a credit limit below ledger-derived outstanding debt', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => now()->addDays(10)->toDateString(),
    ]);
    app(LedgerService::class)->recordPayment($entry, '100.00');
    $customer->update(['current_balance' => '0.00']);

    $this->actingAs($owner)->put(route('customers.update', $customer), [
        'full_name' => $customer->full_name,
        'contact_number' => $customer->contact_number,
        'email' => $customer->email,
        'residential_landmark' => $customer->residential_landmark,
        'credit_limit' => '399.99',
    ])->assertInvalid(['credit_limit']);

    expect($customer->fresh()->credit_limit)->toBe('1000.00');
});

it('allows a customer to change to another unique email', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create(['email' => 'before@example.test']);

    $this->actingAs($owner)->put(route('customers.update', $customer), [
        'full_name' => $customer->full_name,
        'contact_number' => $customer->contact_number,
        'email' => '  AFTER@example.test ',
        'residential_landmark' => $customer->residential_landmark,
        'credit_limit' => $customer->credit_limit,
    ])->assertRedirect(route('customers.index'));

    expect($customer->fresh()->email)->toBe('after@example.test');
});

it('creates and edits customer records without sending mail', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $customer = Customer::factory()->create();

    $this->actingAs($owner)->put(route('customers.update', $customer), [
        'full_name' => $customer->full_name,
        'contact_number' => $customer->contact_number,
        'email' => $customer->email,
        'residential_landmark' => $customer->residential_landmark,
        'credit_limit' => $customer->credit_limit,
    ])->assertRedirect(route('customers.index'));

    Mail::assertNothingOutgoing();
});
