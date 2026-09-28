<?php

use App\Models\Customer;
use App\Models\SukiPointTransaction;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;

it('awards a point per one hundred pesos across partial repayments', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $service = app(LedgerService::class);
    $entry = $service->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => now()->addDays(30)->toDateString(),
    ]);

    $firstPayment = $service->recordPayment($entry, '50.00');

    expect($customer->sukiPoints()->first()->points_balance)->toBe(0);
    expect($customer->sukiPoints()->first()->repayment_remainder_cents)->toBe(5000);
    expect($firstPayment->sukiPointTransaction->points_awarded)->toBe(0);

    expect(fn () => SukiPointTransaction::create([
        'customer_id' => $customer->id,
        'payment_id' => $firstPayment->id,
        'paid_amount_cents' => 5000,
        'points_awarded' => 0,
        'remainder_before_cents' => 5000,
        'remainder_after_cents' => 10000,
    ]))->toThrow(QueryException::class);

    $service->recordPayment($entry, '70.00');

    expect($customer->sukiPoints()->first()->points_balance)->toBe(1);
    expect($customer->sukiPoints()->first()->repayment_remainder_cents)->toBe(2000);

    $service->recordPayment($entry, '250.00');

    expect($customer->sukiPoints()->first()->points_balance)->toBe(3);
    expect($customer->sukiPoints()->first()->repayment_remainder_cents)->toBe(7000);
    expect($customer->fresh()->current_balance)->toBe('130.00');
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(0);

    $service->recordPayment($entry, '130.00');
    $sukiPoints = $customer->sukiPoints()->first();

    expect($sukiPoints->points_balance)->toBe(5);
    expect($sukiPoints->repayment_remainder_cents)->toBe(0);
    expect($sukiPoints->completed_cycles)->toBe(1);
    expect(SukiPointTransaction::query()->where('customer_id', $customer->id)->count())->toBe(4);
    expect(SukiPointTransaction::query()->where('customer_id', $customer->id)->sum('points_awarded'))->toBe(5);
});
