<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\SukiPoint;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Str;

it('redirects guests away from the record credit form and submission', function () {
    $this->get('/credits/create')
        ->assertRedirect(route('login'));

    $this->post('/credits', [])
        ->assertRedirect(route('login'));
});

it('shows the form with the selected customer financial summary', function () {
    $customer = Customer::factory()->create([
        'full_name' => 'Juan Dela Cruz',
        'contact_number' => '+639171234567',
        'credit_limit' => '5000.00',
    ]);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '3500.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/credits/create?customer_id='.$customer->id)
        ->assertOk()
        ->assertViewHas('selectedCustomerId', $customer->id)
        ->assertSeeText('₱3500.00')
        ->assertSeeText('₱1500.00');
});

it('shows an add-customer empty state when no customers exist', function () {
    $this->actingAs(User::factory()->create())
        ->get('/credits/create')
        ->assertOk()
        ->assertSeeText('No customers available.')
        ->assertSee('Add customer')
        ->assertDontSee('name="amount"', false);
});

it('records credit with optional details and redirects to confirmation', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs(User::factory()->create())
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '500.00',
            'itemized_list' => 'rice, sardines, softdrinks',
            'note' => 'Customer will pay Friday',
            'repayment_deadline' => '2026-10-07',
            'idempotency_key' => $idempotencyKey,
        ]);

    $entry = LedgerEntry::query()->sole();

    $response->assertRedirect(route('credits.confirmation', $entry))
        ->assertSessionHas('status', 'Credit recorded successfully.');
    $this->assertDatabaseHas('ledger_entries', [
        'id' => $entry->id,
        'customer_id' => $customer->id,
        'amount' => '500.00',
        'itemized_list' => '["rice, sardines, softdrinks"]',
        'note' => 'Customer will pay Friday',
        'running_balance' => '500.00',
        'idempotency_key' => $idempotencyKey,
    ]);
    expect($entry->repayment_deadline->toDateString())->toBe('2026-10-07');
    expect($customer->fresh()->current_balance)->toBe('500.00');
});

it('records credit without optional details or a repayment deadline', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);

    $this->actingAs(User::factory()->create())
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '250.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    $entry = LedgerEntry::query()->sole();

    expect($entry->itemized_list)->toBeNull();
    expect($entry->note)->toBeNull();
    expect($entry->repayment_deadline)->toBeNull();
    expect($entry->running_balance)->toBe('250.00');
});

it('allows a credit exactly equal to available credit', function () {
    $customer = Customer::factory()->create([
        'credit_limit' => '5000.00',
        'current_balance' => '0.00',
    ]);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '4000.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    expect($customer->fresh()->current_balance)->toBe('5000.00');
    expect(LedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(2);
});

it('hard-blocks credit above available credit and preserves entered values', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '4000.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/credits/create?customer_id='.$customer->id)
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '1500.00',
            'itemized_list' => 'rice and coffee',
            'note' => 'Keep this value',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/credits/create?customer_id='.$customer->id)
        ->assertSessionHasErrors(['amount' => 'Credit limit exceeded. Current balance: ₱4000.00. Available credit: ₱1000.00. Requested credit: ₱1500.00.'])
        ->assertSessionHasInput('itemized_list', 'rice and coffee')
        ->assertSessionHasInput('note', 'Keep this value');

    expect($customer->fresh()->current_balance)->toBe('4000.00');
    expect(LedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('rejects missing and invalid customer or amount input', function (array $payload, array $invalidFields) {
    $customer = Customer::factory()->create();
    $payload = array_merge([
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'idempotency_key' => (string) Str::uuid(),
    ], $payload);

    $this->actingAs(User::factory()->create())
        ->from('/credits/create')
        ->post('/credits', $payload)
        ->assertRedirect('/credits/create')
        ->assertSessionHasErrors($invalidFields);

    $this->assertDatabaseCount('ledger_entries', 0);
})->with([
    'missing customer' => [['customer_id' => ''], ['customer_id']],
    'unknown customer' => [['customer_id' => 999999], ['customer_id']],
    'missing amount' => [['amount' => ''], ['amount']],
    'zero amount' => [['amount' => '0'], ['amount']],
    'negative amount' => [['amount' => '-1.00'], ['amount']],
    'non-numeric amount' => [['amount' => 'five'], ['amount']],
    'excess precision' => [['amount' => '1.001'], ['amount']],
]);

it('rejects an invalid optional repayment deadline', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->from('/credits/create')
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '10.00',
            'repayment_deadline' => 'not-a-date',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/credits/create')
        ->assertSessionHasErrors(['repayment_deadline']);

    $this->assertDatabaseCount('ledger_entries', 0);
});

it('returns the existing credit when a submission is repeated with the same key', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $idempotencyKey = (string) Str::uuid();
    $owner = User::factory()->create();
    $payload = [
        'customer_id' => $customer->id,
        'amount' => '500.00',
        'idempotency_key' => $idempotencyKey,
    ];

    $firstResponse = $this->actingAs($owner)->post('/credits', $payload);
    $firstEntry = LedgerEntry::query()->sole();
    $secondResponse = $this->post('/credits', $payload);

    $firstResponse->assertRedirect(route('credits.confirmation', $firstEntry));
    $secondResponse->assertRedirect(route('credits.confirmation', $firstEntry));
    $this->assertDatabaseCount('ledger_entries', 1);
    expect($customer->fresh()->current_balance)->toBe('500.00');
});

it('confirms the customer amount and resulting balance', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => '250.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('credits.confirmation', $entry))
        ->assertOk()
        ->assertSeeText('Credit Recorded')
        ->assertSeeText($customer->full_name)
        ->assertSeeText('₱250.00')
        ->assertSeeText('₱250.00')
        ->assertSee(route('customers.show', $customer))
        ->assertSee(route('credits.create', ['customer_id' => $customer->id]));
});

it('does not change Suki Points or cycle progress when recording credit', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $sukiPoints = SukiPoint::factory()->for($customer)->create([
        'points_balance' => 8,
        'repayment_remainder_cents' => 6000,
        'completed_cycles' => 2,
        'current_cycle_count' => 2,
        'reward_eligible' => false,
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    expect($sukiPoints->fresh()->points_balance)->toBe(8);
    expect($sukiPoints->fresh()->repayment_remainder_cents)->toBe(6000);
    expect($sukiPoints->fresh()->completed_cycles)->toBe(2);
    expect($sukiPoints->fresh()->current_cycle_count)->toBe(2);
    expect($sukiPoints->fresh()->reward_eligible)->toBeFalse();
});

it('rolls back a credit if ledger persistence fails', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);

    LedgerEntry::creating(function (): void {
        throw new RuntimeException('Simulated persistence failure.');
    });

    $this->actingAs(User::factory()->create())
        ->from('/credits/create')
        ->post('/credits', [
            'customer_id' => $customer->id,
            'amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/credits/create')
        ->assertSessionHasErrors(['credit']);

    $this->assertDatabaseCount('ledger_entries', 0);
    expect($customer->fresh()->current_balance)->toBe('0.00');
});
