<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\SukiPoint;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Carbon;

test('the root redirects guests to login', function () {
    $this->get('/')
        ->assertRedirect(route('login'));
});

it('redirects guests away from a customer ledger', function () {
    $customer = Customer::factory()->create();

    $this->get(route('customers.show', $customer))
        ->assertRedirect(route('login'));
});

it('shows customer details and zero-value ledger state when there is no history', function () {
    $customer = Customer::factory()->create([
        'full_name' => 'Juan Dela Cruz',
        'contact_number' => '+639171234567',
        'email' => 'juan@example.test',
        'residential_landmark' => 'Near the public market',
        'credit_limit' => '5000.00',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer));

    $response->assertOk()
        ->assertSeeText('Juan Dela Cruz')
        ->assertSeeText('+639171234567')
        ->assertSeeText('juan@example.test')
        ->assertSeeText('Near the public market')
        ->assertSeeText('₱5000.00')
        ->assertSeeText('No transactions yet.')
        ->assertSeeText('Record Credit')
        ->assertSeeText('No Balance to Pay')
        ->assertViewHas('ledger', fn (array $ledger): bool => $ledger['outstanding_balance'] === '0.00'
            && $ledger['available_credit'] === '5000.00'
            && $ledger['is_overdue'] === false
            && $ledger['rewards']['points_balance'] === 0
            && $ledger['rewards']['remainder_cents'] === 0
            && $ledger['rewards']['completed_cycles'] === 0
        );
});

it('shows chronological credits and payments with cumulative running balances', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);

    $firstCredit = $ledgerService->recordCredit($customer, [
        'amount' => '1000.00',
        'itemized_list' => ['Rice', 'Coffee'],
        'transaction_date' => '2026-09-28',
        'repayment_deadline' => '2026-10-10',
    ]);
    $firstPayment = $ledgerService->recordPayment($firstCredit, '250.00', '2026-09-29');
    $secondCredit = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '500.00',
        'itemized_list' => ['Tea'],
        'transaction_date' => '2026-10-01',
        'repayment_deadline' => '2026-10-15',
    ]);
    $ledgerService->recordPayment($secondCredit, '150.00', '2026-10-01');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer));

    $response->assertOk()
        ->assertSeeText('Rice, Coffee')
        ->assertSeeText('Tea')
        ->assertViewHas('ledger', function (array $ledger) use ($firstPayment): bool {
            $transactions = $ledger['transactions']->map(fn (array $transaction): array => [
                $transaction['type'],
                $transaction['amount'],
                $transaction['running_balance'],
            ])->all();

            return $ledger['outstanding_balance'] === '1100.00'
                && $transactions === [
                    ['credit', '1000.00', '1000.00'],
                    ['payment', '250.00', '750.00'],
                    ['credit', '500.00', '1250.00'],
                    ['payment', '150.00', '1100.00'],
                ]
                && $ledger['transactions']->get(1)['id'] === 'payment-'.$firstPayment->id;
        });
});

it('marks only unpaid past-deadline credit as overdue', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);

    $partiallyPaidOverdue = $ledgerService->recordCredit($customer, [
        'amount' => '1000.00',
        'transaction_date' => '2026-09-20',
        'repayment_deadline' => '2026-09-30',
    ]);
    $ledgerService->recordPayment($partiallyPaidOverdue, '400.00', '2026-09-25');

    $fullyPaidOverdue = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '300.00',
        'transaction_date' => '2026-09-10',
        'repayment_deadline' => '2026-09-20',
    ]);
    $ledgerService->recordPayment($fullyPaidOverdue, '300.00', '2026-09-19');

    $futureCredit = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '200.00',
        'transaction_date' => '2026-09-30',
        'repayment_deadline' => '2026-10-05',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer));

    $response->assertOk()
        ->assertSeeText('Overdue')
        ->assertSeeText('600.00 remaining')
        ->assertViewHas('ledger', function (array $ledger) use ($partiallyPaidOverdue, $fullyPaidOverdue, $futureCredit): bool {
            $overdueStatuses = $ledger['transactions']
                ->where('type', 'credit')
                ->keyBy('id')
                ->map(fn (array $transaction): bool => $transaction['is_overdue']);

            return $ledger['is_overdue']
                && $ledger['overdue_entry_count'] === 1
                && $overdueStatuses['credit-'.$partiallyPaidOverdue->id] === true
                && $overdueStatuses['credit-'.$fullyPaidOverdue->id] === false
                && $overdueStatuses['credit-'.$futureCredit->id] === false;
        });
});

it('never reports negative available credit when debt exceeds the limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => '500.00']);
    LedgerEntry::factory()->for($customer)->create([
        'amount' => '700.00',
        'running_balance' => '700.00',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertViewHas('ledger', fn (array $ledger): bool => $ledger['outstanding_balance'] === '700.00'
            && $ledger['available_credit'] === '0.00'
        );
});

it('links each customer record to its ledger page', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('customers.index'))
        ->assertOk()
        ->assertSeeText('Open ledger')
        ->assertSee(route('customers.show', $customer));
});

it('keeps points carry-forward separate from completed cycle reward progress', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    SukiPoint::factory()->for($customer)->create([
        'points_balance' => 10,
        'repayment_remainder_cents' => 3000,
        'completed_cycles' => 1,
        'reward_threshold' => 3,
        'reward_eligible' => false,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer));

    $response->assertOk()
        ->assertSeeText('10')
        ->assertSeeText('₱30.00 / ₱100.00')
        ->assertSeeText('1 / 3')
        ->assertDontSeeText('Three-cycle reward achieved');
});

it('shows the three-cycle reward as achieved without resetting points', function () {
    $customer = Customer::factory()->create();
    SukiPoint::factory()->for($customer)->create([
        'points_balance' => 10,
        'repayment_remainder_cents' => 7000,
        'completed_cycles' => 3,
        'reward_threshold' => 3,
        'reward_eligible' => true,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSeeText('10')
        ->assertSeeText('₱70.00 / ₱100.00')
        ->assertSeeText('3 / 3')
        ->assertSeeText('Three-cycle reward achieved');
});

it('returns a friendly not-found page for an unknown customer', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('customers.show', 999999))
        ->assertNotFound()
        ->assertSeeText('Customer not found.');
});
