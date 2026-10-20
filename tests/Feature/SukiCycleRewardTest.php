<?php

use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Services\LedgerService;
use App\Services\SukiPointService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Record a credit and pay it off with one cash payment (one full debt cycle).
 *
 * @return array{payment_batch_id: string, payments: Collection, debt_cycle_completed: bool}
 */
function completeCycle(Customer $customer, string $amount): array
{
    $ledgerService = app(LedgerService::class);
    $ledgerService->recordCredit($customer, ['amount' => $amount]);

    return $ledgerService->recordCustomerPayment($customer, [
        'amount' => $amount,
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

it('counts the first and second completed cycles without a reward', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);

    completeCycle($customer, '250.00');
    completeCycle($customer, '400.00');

    $sukiPoints = $customer->sukiPoints()->first();
    expect($sukiPoints->completed_cycles)->toBe(2);
    expect($sukiPoints->current_cycle_count)->toBe(2);
    expect($sukiPoints->streak_repaid_cents)->toBe(65000);
    expect($sukiPoints->points_balance)->toBe(6);
    expect($customer->debtCycleCompletions()->orderBy('cycle_number')->get()->map->only(['cycle_number', 'streak_position', 'repaid_cents', 'reward_points'])->all())
        ->toBe([
            ['cycle_number' => 1, 'streak_position' => 1, 'repaid_cents' => 25000, 'reward_points' => 0],
            ['cycle_number' => 2, 'streak_position' => 2, 'repaid_cents' => 40000, 'reward_points' => 0],
        ]);
});

it('gives a reward based on cash repaid across the streak on the third cycle and resets the streak', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);

    completeCycle($customer, '250.00');
    completeCycle($customer, '400.00');
    $thirdCycle = completeCycle($customer, '375.50');

    $sukiPoints = $customer->sukiPoints()->first();
    $reward = $customer->debtCycleCompletions()->where('cycle_number', 3)->sole();
    expect($reward->streak_position)->toBe(3);
    expect($reward->reward_points)->toBe(10);
    expect($reward->completing_payment_id)->toBe($thirdCycle['payments']->last()->id);
    expect($sukiPoints->current_cycle_count)->toBe(0);
    expect($sukiPoints->streak_repaid_cents)->toBe(0);
    expect($sukiPoints->completed_cycles)->toBe(3);
    expect($sukiPoints->points_balance)->toBe(10 + 10);
});

it('starts a new streak after a reward and rewards it again on its third cycle', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);

    foreach (['100.00', '100.00', '100.00', '300.00', '300.00', '300.00'] as $amount) {
        completeCycle($customer, $amount);
    }

    expect($customer->debtCycleCompletions()->where('reward_points', '>', 0)->pluck('reward_points', 'cycle_number')->all())
        ->toBe([3 => 3, 6 => 9]);
    expect($customer->sukiPoints()->first()->current_cycle_count)->toBe(0);
});

it('includes partial payments made earlier in the cycle in the reward', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    completeCycle($customer, '100.00');
    completeCycle($customer, '100.00');
    $ledgerService->recordCredit($customer, ['amount' => '500.00']);

    $ledgerService->recordCustomerPayment($customer, ['amount' => '199.99', 'idempotency_key' => (string) Str::uuid()]);

    expect($customer->debtCycleCompletions()->count())->toBe(2);
    expect($customer->sukiPoints()->first()->current_cycle_count)->toBe(2);

    $ledgerService->recordCustomerPayment($customer, ['amount' => '300.01', 'idempotency_key' => (string) Str::uuid()]);

    expect($customer->debtCycleCompletions()->where('cycle_number', 3)->sole())
        ->repaid_cents->toBe(50000)
        ->reward_points->toBe(7);
});

it('does not complete a cycle while any credit remains unpaid', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    $ledgerService->recordCredit($customer, ['amount' => '500.00']);
    $ledgerService->recordCredit($customer, ['amount' => '800.00']);

    $ledgerService->recordCustomerPayment($customer, ['amount' => '500.00', 'idempotency_key' => (string) Str::uuid()]);

    expect($customer->debtCycleCompletions()->count())->toBe(0);
    expect($customer->sukiPoints()->first()->current_cycle_count)->toBe(0);
});

it('also completes cycles paid through a single credit entry', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    $entry = $ledgerService->recordCredit($customer, ['amount' => '200.00']);

    $payment = $ledgerService->recordPayment($entry, '200.00');

    expect($customer->debtCycleCompletions()->sole()->completing_payment_id)->toBe($payment->id);
});

it('does not count a repeated payment submission as another cycle', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    completeCycle($customer, '100.00');
    completeCycle($customer, '100.00');
    $ledgerService->recordCredit($customer, ['amount' => '100.00']);
    $submission = ['amount' => '100.00', 'idempotency_key' => (string) Str::uuid()];

    $ledgerService->recordCustomerPayment($customer, $submission);
    $ledgerService->recordCustomerPayment($customer, $submission);

    expect($customer->debtCycleCompletions()->count())->toBe(3);
    expect($customer->debtCycleCompletions()->sum('reward_points'))->toEqual(3);
    expect($customer->sukiPoints()->first()->points_balance)->toBe(3 + 3);
});

it('allows only one completion per payment and per cycle number in the database', function (array $duplicate) {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $completingPayment = completeCycle($customer, '100.00')['payments']->last();

    expect(fn () => DebtCycleCompletion::factory()->for($customer)->create([
        'completing_payment_id' => $completingPayment->id,
        'cycle_number' => 2,
        ...$duplicate,
    ]))->toThrow(QueryException::class);
})->with([
    'same completing payment' => [[]],
    'same cycle number' => [['completing_payment_id' => null, 'cycle_number' => 1]],
]);

it('rolls back the payment, points, and streak when recording the cycle fails', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    completeCycle($customer, '100.00');
    DebtCycleCompletion::factory()->for($customer)->create(['cycle_number' => 2]);
    $ledgerService->recordCredit($customer, ['amount' => '300.00']);

    expect(fn () => $ledgerService->recordCustomerPayment($customer, [
        'amount' => '300.00',
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(QueryException::class);

    $sukiPoints = $customer->sukiPoints()->first();
    expect($customer->payments()->count())->toBe(1);
    expect($sukiPoints->completed_cycles)->toBe(1);
    expect($sukiPoints->current_cycle_count)->toBe(1);
    expect($sukiPoints->points_balance)->toBe(1);
    expect($sukiPoints->streak_repaid_cents)->toBe(10000);
    expect($ledgerService->outstandingBalanceInCents($customer))->toBe(30000);
});

it('uses the configured reward rate for future rewards only', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    foreach (['100.00', '100.00', '100.00'] as $amount) {
        completeCycle($customer, $amount);
    }

    config(['suki_points.cycle_reward_pesos_per_point' => 50]);
    foreach (['100.00', '100.00', '100.00'] as $amount) {
        completeCycle($customer, $amount);
    }

    expect($customer->debtCycleCompletions()->where('reward_points', '>', 0)->pluck('reward_points', 'cycle_number')->all())
        ->toBe([3 => 3, 6 => 6]);
});

it('summarizes points, streak, and cycle history for display', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    foreach (['100.00', '100.00', '100.00', '250.00'] as $amount) {
        completeCycle($customer, $amount);
    }

    $summary = app(SukiPointService::class)->summaryFor($customer);

    expect($summary)
        ->points_balance->toBe(5 + 3)
        ->completed_cycles->toBe(4)
        ->current_streak->toBe(1)
        ->cycles_until_reward->toBe(2)
        ->streak_repaid->toBe('250.00')
        ->projected_reward_points->toBe(2)
        ->total_cycle_reward_points->toBe(3);
    expect(array_column($summary['recent_cycles'], 'cycle_number'))->toBe([4, 3, 2, 1]);
    expect($summary['recent_cycles'][1]['reward_points'])->toBe(3);
});

it('summarizes a customer who has never had debt', function () {
    $summary = app(SukiPointService::class)->summaryFor(Customer::factory()->create());

    expect($summary)
        ->points_balance->toBe(0)
        ->current_streak->toBe(0)
        ->cycles_until_reward->toBe(3)
        ->recent_cycles->toBe([]);
});
