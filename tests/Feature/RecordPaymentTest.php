<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Models\SukiPointTransaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('redirects guests away from the Record Payment form and submission', function () {
    $this->get('/payments/create')
        ->assertRedirect(route('login'));

    $this->post('/payments', [])
        ->assertRedirect(route('login'));
});

it('shows a selected customer balance on the payment form', function () {
    $customer = Customer::factory()->create(['credit_limit' => '2000.00']);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '1500.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/payments/create?customer_id='.$customer->id)
        ->assertOk()
        ->assertViewHas('selectedCustomerId', $customer->id)
        ->assertSeeText('₱1500.00');
});

it('shows a zero-balance state and disables payment when the customer has no debt', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get('/payments/create?customer_id='.$customer->id)
        ->assertOk()
        ->assertSeeText('₱0.00')
        ->assertSeeText('No outstanding balance to pay.')
        ->assertSee('data-payment-submit disabled', false);
});

it('shows an add-customer state when there are no customers', function () {
    $this->actingAs(User::factory()->create())
        ->get('/payments/create')
        ->assertOk()
        ->assertSeeText('No customers available.')
        ->assertSee('Add customer')
        ->assertDontSee('name="amount"', false);
});

it('records a partial payment with a note and confirms the remaining balance', function () {
    $customer = Customer::factory()->create(['credit_limit' => '2000.00']);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => '1500.00',
        'repayment_deadline' => null,
    ]);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs(User::factory()->create())
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '400.00',
            'note' => 'Paid in cash',
            'idempotency_key' => $idempotencyKey,
        ]);

    $payment = Payment::query()->sole();

    $response->assertRedirect(route('payments.confirmation', $payment->payment_batch_id))
        ->assertSessionHas('status', 'Payment recorded successfully.');
    $this->assertDatabaseHas('payments', [
        'customer_id' => $customer->id,
        'ledger_entry_id' => $entry->id,
        'amount' => '400.00',
        'note' => 'Paid in cash',
        'idempotency_key' => $idempotencyKey,
    ]);
    expect($customer->fresh()->current_balance)->toBe('1100.00');
    expect($entry->fresh()->outstanding_balance)->toBe('1100.00');
    expect($customer->sukiPoints()->first()->points_balance)->toBe(4);
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(0);

    $this->get(route('payments.confirmation', $payment->payment_batch_id))
        ->assertOk()
        ->assertSeeText('Payment Recorded')
        ->assertSeeText($customer->full_name)
        ->assertSeeText('₱400.00')
        ->assertSeeText('₱1500.00')
        ->assertSeeText('₱1100.00');
});

it('records a payment without an optional note', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->note)->toBeNull();
    expect($payment->ledger_entry_id)->toBe($entry->id);
});

it('allocates a payment to the oldest outstanding credits first and groups the receipt in the ledger', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    $oldestCredit = $ledgerService->recordCredit($customer, [
        'amount' => '500.00',
        'transaction_date' => '2026-09-20',
        'repayment_deadline' => '2026-10-10',
    ]);
    $newerCredit = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '1000.00',
        'transaction_date' => '2026-09-25',
        'repayment_deadline' => '2026-10-15',
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '700.00',
            'note' => 'Split across oldest balances',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    $allocations = Payment::query()->orderBy('id')->get();

    expect($allocations->map(fn (Payment $payment): array => [$payment->ledger_entry_id, $payment->amount])->all())
        ->toBe([
            [$oldestCredit->id, '500.00'],
            [$newerCredit->id, '200.00'],
        ]);
    expect($oldestCredit->fresh()->outstanding_balance)->toBe('0.00');
    expect($newerCredit->fresh()->outstanding_balance)->toBe('800.00');
    expect($customer->fresh()->current_balance)->toBe('800.00');

    $this->get(route('customers.show', $customer))
        ->assertOk()
        ->assertViewHas('ledger', function (array $ledger): bool {
            $paymentTransactions = $ledger['transactions']->where('type', 'payment');

            return $paymentTransactions->count() === 1
                && $paymentTransactions->first()['amount'] === '700.00'
                && $paymentTransactions->first()['note'] === 'Split across oldest balances'
                && $paymentTransactions->first()['running_balance'] === '800.00';
        });
});

it('keeps overdue status through partial payment and clears it when the overdue debt is settled', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $customer = Customer::factory()->create(['credit_limit' => '2000.00']);
    $ledgerService = app(LedgerService::class);
    $overdueCredit = $ledgerService->recordCredit($customer, [
        'amount' => '500.00',
        'transaction_date' => '2026-09-01',
        'repayment_deadline' => '2026-09-30',
    ]);
    $futureCredit = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '500.00',
        'transaction_date' => '2026-09-20',
        'repayment_deadline' => '2026-10-10',
    ]);
    $owner = User::factory()->create();

    $this->actingAs($owner)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '100.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $this->get(route('customers.show', $customer))
        ->assertViewHas('ledger', function (array $ledger) use ($overdueCredit, $futureCredit): bool {
            $credits = $ledger['transactions']->where('type', 'credit')->keyBy('id');

            return $ledger['is_overdue']
                && $credits['credit-'.$overdueCredit->id]['is_overdue']
                && ! $credits['credit-'.$futureCredit->id]['is_overdue']
                && $credits['credit-'.$overdueCredit->id]['remaining_amount'] === '400.00';
        });

    $this->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '400.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $this->get(route('customers.show', $customer))
        ->assertViewHas('ledger', function (array $ledger) use ($overdueCredit, $futureCredit): bool {
            $credits = $ledger['transactions']->where('type', 'credit')->keyBy('id');

            return ! $ledger['is_overdue']
                && $credits['credit-'.$overdueCredit->id]['remaining_amount'] === '0.00'
                && ! $credits['credit-'.$overdueCredit->id]['is_overdue']
                && $credits['credit-'.$futureCredit->id]['remaining_amount'] === '500.00';
        });
});

it('completes a debt cycle only when a full payment clears all outstanding credits', function () {
    $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $ledgerService = app(LedgerService::class);
    $firstCredit = $ledgerService->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);
    $secondCredit = $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '1000.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '1500.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    expect($customer->fresh()->current_balance)->toBe('0.00');
    expect($firstCredit->fresh()->outstanding_balance)->toBe('0.00');
    expect($secondCredit->fresh()->outstanding_balance)->toBe('0.00');
    expect($customer->sukiPoints()->first()->points_balance)->toBe(15);
    expect($customer->sukiPoints()->first()->completed_cycles)->toBe(1);
    expect(SukiPointTransaction::query()->where('customer_id', $customer->id)->sum('paid_amount_cents'))->toBe(150000);

    $paymentBatchId = Payment::query()->where('customer_id', $customer->id)->value('payment_batch_id');

    $this->get(route('payments.confirmation', $paymentBatchId))
        ->assertOk()
        ->assertSeeText('₱0.00')
        ->assertSeeText('Debt fully paid');
});

it('blocks payments greater than the actual outstanding balance and preserves the input', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/payments/create?customer_id='.$customer->id)
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '700.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/payments/create?customer_id='.$customer->id)
        ->assertSessionHasErrors(['amount' => 'Payment cannot exceed the customer outstanding balance of ₱500.00.'])
        ->assertSessionHasInput('amount', '700.00');

    $this->assertDatabaseCount('payments', 0);
    expect($customer->fresh()->current_balance)->toBe('500.00');
});

it('rejects a payment when the selected customer has zero balance', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->from('/payments/create?customer_id='.$customer->id)
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '1.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/payments/create?customer_id='.$customer->id)
        ->assertSessionHasErrors(['amount']);

    $this->assertDatabaseCount('payments', 0);
});

it('rejects missing or invalid customer and payment amount input', function (array $payload, array $invalidFields) {
    $customer = Customer::factory()->create();
    $entry = app(LedgerService::class)->recordCredit($customer, [
        'amount' => '100.00',
        'repayment_deadline' => null,
    ]);
    $payload = array_merge([
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'idempotency_key' => (string) Str::uuid(),
    ], $payload);

    $this->actingAs(User::factory()->create())
        ->from('/payments/create')
        ->post('/payments', $payload)
        ->assertRedirect('/payments/create')
        ->assertSessionHasErrors($invalidFields);

    expect($entry->payments()->count())->toBe(0);
})->with([
    'missing customer' => [['customer_id' => ''], ['customer_id']],
    'unknown customer' => [['customer_id' => 999999], ['customer_id']],
    'missing amount' => [['amount' => ''], ['amount']],
    'zero amount' => [['amount' => '0'], ['amount']],
    'negative amount' => [['amount' => '-1.00'], ['amount']],
    'non-numeric amount' => [['amount' => 'money'], ['amount']],
    'excess precision' => [['amount' => '1.001'], ['amount']],
    'oversized note' => [['note' => str_repeat('x', 501)], ['note']],
]);

it('returns the existing payment confirmation when the same submission is retried', function () {
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    app(LedgerService::class)->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);
    $owner = User::factory()->create();
    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'customer_id' => $customer->id,
        'amount' => '200.00',
        'idempotency_key' => $idempotencyKey,
    ];

    $firstResponse = $this->actingAs($owner)->post('/payments', $payload);
    $paymentBatchId = Payment::query()->whereNotNull('payment_batch_id')->value('payment_batch_id');
    $secondResponse = $this->post('/payments', $payload);

    $firstResponse->assertRedirect(route('payments.confirmation', $paymentBatchId));
    $secondResponse->assertRedirect(route('payments.confirmation', $paymentBatchId));
    $this->assertDatabaseCount('payments', 1);
    expect($customer->fresh()->current_balance)->toBe('300.00');
});

it('rolls back every payment allocation if a later allocation fails', function () {
    $customer = Customer::factory()->create(['credit_limit' => '2000.00']);
    $ledgerService = app(LedgerService::class);
    $ledgerService->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);
    $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);
    SukiPoint::factory()->for($customer)->create();

    Payment::creating(function (Payment $payment): void {
        if ($payment->amount === '100.00') {
            throw new RuntimeException('Simulated payment persistence failure.');
        }
    });

    $this->actingAs(User::factory()->create())
        ->from('/payments/create')
        ->post('/payments', [
            'customer_id' => $customer->id,
            'amount' => '600.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect('/payments/create')
        ->assertSessionHasErrors(['payment']);

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('suki_point_transactions', 0);
    expect($customer->fresh()->current_balance)->toBe('1000.00');
    expect($customer->sukiPoints()->first()->points_balance)->toBe(0);
});
