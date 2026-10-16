<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Store A has an overdue partially paid entry and a current entry, store B
 * (suspended) has a fully paid overdue entry and a customer with no credit,
 * and store C has no customers.
 */
function seedPlatformLedger(): void
{
    $storeA = Store::factory()->create();
    $customerA = Customer::factory()->for($storeA)->create();
    $overdueEntry = LedgerEntry::factory()->for($customerA)->create([
        'amount' => '1000.00',
        'repayment_deadline' => '2026-10-10',
    ]);
    Payment::factory()->for($customerA)->for($overdueEntry)->create([
        'amount' => '400.00',
        'payment_date' => '2026-10-05',
    ]);
    LedgerEntry::factory()->for($customerA)->create([
        'amount' => '500.00',
        'repayment_deadline' => '2026-10-30',
    ]);

    $storeB = Store::factory()->suspended()->create();
    $customerB = Customer::factory()->for($storeB)->create();
    $paidEntry = LedgerEntry::factory()->for($customerB)->create([
        'amount' => '300.00',
        'repayment_deadline' => '2026-10-01',
    ]);
    Payment::factory()->for($customerB)->for($paidEntry)->create([
        'amount' => '300.00',
        'payment_date' => '2026-09-20',
    ]);
    Customer::factory()->for($storeB)->create();

    Store::factory()->create();
}

it('shows platform totals using current remaining balances and period payments', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    seedPlatformLedger();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertViewHas('metrics', [
            'total_stores' => 3,
            'active_stores' => 2,
            'suspended_stores' => 1,
            'total_customers' => 3,
            'outstanding_balance' => '1100.00',
            'payments_received' => '400.00',
            'overdue_balance' => '600.00',
            'stores_with_overdue' => 1,
        ])
        ->assertSee('₱1100.00');
});

it('totals payments only within the selected period', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    seedPlatformLedger();

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk();

    expect($response->viewData('metrics'))
        ->payments_received->toBe('300.00')
        ->outstanding_balance->toBe('1100.00');
});

it('rejects a period that ends before it starts', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.dashboard'))
        ->get(route('admin.dashboard', ['from' => '2026-10-10', 'to' => '2026-10-01']))
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
});

it('shows an empty state when no stores exist', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('No stores are registered yet.')
        ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total_stores'] === 0
            && $metrics['outstanding_balance'] === '0.00'
            && $metrics['payments_received'] === '0.00');
});
