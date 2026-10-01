<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Str;

it('calculates collectibles and overdue customers from ledger and payment records', function () {
    $owner = User::factory()->create();
    $service = app(LedgerService::class);

    $overdueCustomer = Customer::factory()->create(['credit_limit' => '5000.00']);
    $firstOverdueEntry = $service->recordCredit($overdueCustomer, [
        'amount' => '500.00',
        'repayment_deadline' => now()->subDays(10)->toDateString(),
    ]);
    $secondOverdueEntry = $service->recordCredit($overdueCustomer, [
        'amount' => '300.00',
        'repayment_deadline' => now()->subDays(2)->toDateString(),
    ]);
    $service->recordPayment($firstOverdueEntry, '100.00');
    $service->recordPayment($secondOverdueEntry, '100.00');
    $overdueCustomer->update(['current_balance' => '9999.99']);

    $futureCustomer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $service->recordCredit($futureCustomer, [
        'amount' => '200.00',
        'repayment_deadline' => now()->addDays(10)->toDateString(),
    ]);

    $paidCustomer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $paidEntry = $service->recordCredit($paidCustomer, [
        'amount' => '120.00',
        'repayment_deadline' => now()->subDay()->toDateString(),
    ]);
    $service->recordPayment($paidEntry, '120.00');

    $response = $this->actingAs($owner)->get('/dashboard');

    $response->assertOk()
        ->assertViewHas('totalCollectibles', '800.00')
        ->assertViewHas('overdueAccountCount', 1)
        ->assertViewHas('recentActivity', function ($activities) use ($paidCustomer): bool {
            return $activities->count() === 7
                && $activities->contains(fn (array $activity): bool => $activity['type'] === 'Payment received'
                    && $activity['customer_name'] === $paidCustomer->full_name);
        });
});

it('shows zero collectibles, no overdue accounts, and an empty activity state', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertViewHas('totalCollectibles', '0.00')
        ->assertViewHas('overdueAccountCount', 0)
        ->assertViewHas('recentActivity', fn ($activities): bool => $activities->isEmpty())
        ->assertSee('No recent activity yet.');
});

it('keeps a recent payment in the activity list when records share a timestamp', function () {
    $this->travelTo(now()->startOfSecond());
    $owner = User::factory()->create();
    $service = app(LedgerService::class);

    $paymentCustomer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $paymentEntry = $service->recordCredit($paymentCustomer, [
        'amount' => '100.00',
        'repayment_deadline' => now()->addDays(5)->toDateString(),
    ]);
    $service->recordPayment($paymentEntry, '25.00');

    foreach (range(1, 8) as $entryNumber) {
        $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
        $service->recordCredit($customer, [
            'amount' => '10.00',
            'repayment_deadline' => now()->addDays(5)->toDateString(),
        ]);
    }

    $this->actingAs($owner)->get('/dashboard')
        ->assertViewHas('recentActivity', function ($activities): bool {
            return $activities->count() === 8
                && $activities->first()['type'] === 'Payment received';
        });
});

it('shows a FIFO payment batch once in recent dashboard activity', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create(['credit_limit' => '3000.00']);
    $ledgerService = app(LedgerService::class);
    $ledgerService->recordCredit($customer, [
        'amount' => '500.00',
        'repayment_deadline' => null,
    ]);
    $ledgerService->recordCredit($customer->fresh(), [
        'amount' => '1000.00',
        'repayment_deadline' => null,
    ]);

    $ledgerService->recordCustomerPayment($customer, [
        'amount' => '700.00',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $this->actingAs($owner)->get('/dashboard')
        ->assertViewHas('recentActivity', function ($activities) use ($customer): bool {
            $paymentActivities = $activities->where('type', 'Payment received');

            return $paymentActivities->count() === 1
                && $paymentActivities->first()['customer_name'] === $customer->full_name
                && $paymentActivities->first()['amount'] === '700.00';
        });
});
