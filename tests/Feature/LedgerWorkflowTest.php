<?php

use App\Exceptions\CreditLimitExceeded;
use App\Models\Customer;
use App\Services\LedgerService;

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

it('tracks a completed debt cycle for suki points', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => 300,
        'repayment_deadline' => now()->addDays(7)->toDateString(),
    ]);

    app(LedgerService::class)->recordPayment($entry, 300);

    expect($customer->fresh()->current_balance)->toBe('0.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(1);
});
