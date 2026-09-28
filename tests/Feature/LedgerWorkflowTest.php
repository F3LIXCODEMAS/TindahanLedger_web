<?php

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

it('reduces a customer balance after a partial payment', function () {
    $customer = Customer::factory()->create([
        'credit_limit' => 5000,
        'current_balance' => 0,
    ]);
    $service = app(LedgerService::class);
    $entry = $service->recordCredit($customer, [
        'amount' => 1200,
        'itemized_list' => ['Rice', 'Cooking oil'],
        'repayment_deadline' => now()->addDays(14)->toDateString(),
    ]);

    $service->recordPayment($entry, 500);

    expect($customer->fresh()->current_balance)->toBe('700.00');
    expect($entry->fresh()->outstanding_balance)->toBe('700.00');
    $this->assertDatabaseHas('payments', [
        'customer_id' => $customer->id,
        'ledger_entry_id' => $entry->id,
        'amount' => 500,
    ]);
});

it('blocks a credit entry that exceeds the customer limit', function () {
    $customer = Customer::factory()->create([
        'credit_limit' => 1000,
        'current_balance' => 900,
    ]);

    expect(fn () => app(LedgerService::class)->recordCredit($customer, [
        'amount' => 101,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]))->toThrow(CreditLimitExceeded::class);

    $this->assertDatabaseCount('ledger_entries', 0);
    expect($customer->fresh()->current_balance)->toBe('900.00');
});

it('handles exact credit-limit amounts in cents', function () {
    $customer = Customer::factory()->create(['credit_limit' => '100.00']);
    $service = app(LedgerService::class);

    $service->recordCredit($customer, [
        'amount' => '99.99',
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);
    $entry = $service->recordCredit($customer->fresh(), [
        'amount' => '0.01',
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    expect($customer->fresh()->current_balance)->toBe('100.00');
    expect($entry->running_balance)->toBe('100.00');
});

it('keeps running balance as a snapshot from when credit is recorded', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    $service = app(LedgerService::class);
    $firstEntry = $service->recordCredit($customer, [
        'amount' => 500,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    $service->recordPayment($firstEntry, 200);

    expect($firstEntry->fresh()->running_balance)->toBe('500.00');
    expect($firstEntry->fresh()->outstanding_balance)->toBe('300.00');
    expect($customer->fresh()->current_balance)->toBe('300.00');

    $secondEntry = $service->recordCredit($customer->fresh(), [
        'amount' => 100,
        'repayment_deadline' => now()->addDays(14)->toDateString(),
    ]);

    expect($secondEntry->running_balance)->toBe('400.00');
    expect($firstEntry->fresh()->running_balance)->toBe('500.00');
});

it('calculates outstanding balance with exact cents over partial payments', function () {
    $customer = Customer::factory()->create(['credit_limit' => '100.00']);
    $service = app(LedgerService::class);
    $entry = $service->recordCredit($customer, [
        'amount' => '10.10',
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    $service->recordPayment($entry, '0.10');
    $service->recordPayment($entry, '0.20');

    expect($entry->fresh()->outstanding_balance)->toBe('9.80');
    expect($customer->fresh()->current_balance)->toBe('9.80');
});

it('rejects monetary inputs with more than two decimal places', function () {
    $customer = Customer::factory()->create(['credit_limit' => '100.00']);
    $service = app(LedgerService::class);

    expect(fn () => $service->recordCredit($customer, [
        'amount' => '0.001',
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    $entry = $service->recordCredit($customer, [
        'amount' => '10.00',
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    expect(fn () => $service->recordPayment($entry, '1.001'))
        ->toThrow(InvalidArgumentException::class);

    expect($customer->fresh()->current_balance)->toBe('10.00');
    $this->assertDatabaseCount('payments', 0);
});

it('tracks a completed debt cycle for suki points', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => 300,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    app(LedgerService::class)->recordPayment($entry, 300);

    expect($customer->fresh()->current_balance)->toBe('0.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(1);
    expect($customer->sukiPoints()->first()->points_balance)->toBe(3);
});

it('only completes a suki cycle when all customer debt is paid', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $service = app(LedgerService::class);
    $firstEntry = $service->recordCredit($customer, [
        'amount' => 500,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);
    $secondEntry = $service->recordCredit($customer, [
        'amount' => 800,
        'repayment_deadline' => now()->addDays(14)->toDateString(),
    ]);

    $service->recordPayment($firstEntry, 200);
    $service->recordPayment($firstEntry, 300);

    expect($customer->fresh()->current_balance)->toBe('800.00');
    expect($customer->sukiPoints()->first()->points_balance)->toBe(5);
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(0);

    $service->recordPayment($secondEntry, 300);
    expect($customer->fresh()->current_balance)->toBe('500.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(0);

    $service->recordPayment($secondEntry, 250);
    expect($customer->fresh()->current_balance)->toBe('250.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(0);

    $service->recordPayment($secondEntry, 250);

    expect($customer->fresh()->current_balance)->toBe('0.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(1);
    expect($customer->sukiPoints()->first()->points_balance)->toBe(13);

    expect(fn () => $service->recordPayment($secondEntry, 1))
        ->toThrow(PaymentExceedsBalance::class);
    expect($customer->sukiPoints()->first()->fresh()->completed_cycles)->toBe(1);
});

it('rolls back payment and balance changes if suki cycle updating fails', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $service = app(LedgerService::class);
    $entry = $service->recordCredit($customer, [
        'amount' => 300,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);
    $sukiPoints = SukiPoint::factory()->for($customer)->create();

    SukiPoint::updating(function (): void {
        throw new RuntimeException('Suki update failed.');
    });

    expect(fn () => $service->recordPayment($entry, 300))
        ->toThrow(RuntimeException::class, 'Suki update failed.');

    expect($customer->fresh()->current_balance)->toBe('300.00');
    expect($entry->fresh()->outstanding_balance)->toBe('300.00');
    expect($sukiPoints->fresh()->completed_cycles)->toBe(0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('suki_point_transactions', 0);
});

it('preserves customer ledger and payment history from cascading deletes', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => 300,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);
    $payment = app(LedgerService::class)->recordPayment($entry, 100);

    expect(fn () => $entry->delete())->toThrow(QueryException::class);
    expect(fn () => $customer->delete())->toThrow(QueryException::class);

    $this->assertModelExists($customer->fresh());
    $this->assertModelExists(LedgerEntry::findOrFail($entry->id));
    $this->assertModelExists(Payment::findOrFail($payment->id));
});

it('uses a configured password for the seeded owner account', function () {
    config(['app.demo_owner_password' => 'only-for-this-test-password']);

    $this->seed();

    $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();

    expect(Hash::check('only-for-this-test-password', $owner->password))->toBeTrue();
    expect(Hash::check('password', $owner->password))->toBeFalse();
});

it('does not duplicate demo customers or transactions when seeded repeatedly', function () {
    config(['app.demo_owner_password' => 'only-for-this-test-password']);

    $this->seed();
    $this->assertDatabaseCount('customers', 5);
    $this->assertDatabaseCount('ledger_entries', 8);
    $this->assertDatabaseCount('payments', 5);

    $this->seed();

    $this->assertDatabaseCount('customers', 5);
    $this->assertDatabaseCount('ledger_entries', 8);
    $this->assertDatabaseCount('payments', 5);
});
