<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.timezone' => 'Asia/Manila']);
});

function analyticsAs(): User
{
    return User::factory()->superAdmin()->create();
}

it('forbids store owners from the analytics page', function () {
    $this->actingAs(User::factory()->withStore()->create())
        ->get(route('admin.analytics'))
        ->assertForbidden();
});

it('groups credits and payments into zero-filled periods', function (string $interval, array $expectedTrends) {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->create(['credit_limit' => '100000.00']);
    $firstEntry = LedgerEntry::factory()->for($customer)->create(['amount' => '100.00', 'transaction_date' => '2026-10-05']);
    $secondEntry = LedgerEntry::factory()->for($customer)->create(['amount' => '250.00', 'transaction_date' => '2026-10-07']);
    Payment::factory()->for($customer)->for($firstEntry)->create(['amount' => '100.00', 'payment_date' => '2026-10-13', 'payment_batch_id' => 'batch-1']);
    Payment::factory()->for($customer)->for($secondEntry)->create(['amount' => '50.00', 'payment_date' => '2026-10-13', 'payment_batch_id' => 'batch-1']);

    $response = $this->actingAs(analyticsAs())->get(route('admin.analytics', [
        'from' => '2026-10-05',
        'to' => '2026-10-14',
        'interval' => $interval,
    ]))->assertOk();

    $trends = collect($response->viewData('trends'))
        ->map(fn (array $bucket): array => [$bucket['label'], $bucket['credit_amount'], $bucket['credit_count'], $bucket['payment_amount'], $bucket['payment_count']])
        ->filter(fn (array $bucket): bool => $bucket[2] > 0 || $bucket[4] > 0 || in_array($bucket[0], ['Oct 6', 'Week of Oct 5'], true))
        ->values()
        ->all();

    expect($trends)->toBe($expectedTrends);
    expect(count($response->viewData('trends')))->toBe(match ($interval) {
        'day' => 10,
        'week' => 2,
        'month' => 1,
    });
})->with([
    'daily' => ['day', [
        ['Oct 5', '100.00', 1, '0.00', 0],
        ['Oct 6', '0.00', 0, '0.00', 0],
        ['Oct 7', '250.00', 1, '0.00', 0],
        ['Oct 13', '0.00', 0, '150.00', 1],
    ]],
    'weekly' => ['week', [
        ['Week of Oct 5', '350.00', 2, '0.00', 0],
        ['Week of Oct 12', '0.00', 0, '150.00', 1],
    ]],
    'monthly' => ['month', [
        ['Oct 2026', '350.00', 2, '150.00', 1],
    ]],
]);

it('places unpaid amounts in aging groups by days past the deadline', function (string $deadline, string $expectedBucket) {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->create();
    $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '300.00', 'repayment_deadline' => $deadline]);
    Payment::factory()->for($customer)->for($entry)->create(['amount' => '100.00']);

    $response = $this->actingAs(analyticsAs())->get(route('admin.analytics'))->assertOk();

    $nonEmptyBuckets = collect($response->viewData('agingBuckets'))
        ->filter(fn (array $bucket): bool => $bucket['entry_count'] > 0)
        ->map(fn (array $bucket): array => [$bucket['key'], $bucket['remaining_amount']])
        ->values()
        ->all();

    expect($nonEmptyBuckets)->toBe([[$expectedBucket, '200.00']]);
})->with([
    'due today' => ['2026-10-16', 'current'],
    '1 day overdue' => ['2026-10-15', 'overdue_1_7'],
    '7 days overdue' => ['2026-10-09', 'overdue_1_7'],
    '8 days overdue' => ['2026-10-08', 'overdue_8_30'],
    '30 days overdue' => ['2026-09-16', 'overdue_8_30'],
    '31 days overdue' => ['2026-09-15', 'overdue_over_30'],
]);

it('leaves fully paid credits out of aging and counts customers with overdue debt once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    $overdueCustomer = Customer::factory()->create();
    LedgerEntry::factory()->count(2)->for($overdueCustomer)->create(['amount' => '100.00', 'repayment_deadline' => '2026-10-01']);
    $paidCustomer = Customer::factory()->create();
    $paidEntry = LedgerEntry::factory()->for($paidCustomer)->create(['amount' => '100.00', 'repayment_deadline' => '2026-10-01']);
    Payment::factory()->for($paidCustomer)->for($paidEntry)->create(['amount' => '100.00']);
    LedgerEntry::factory()->for(Customer::factory())->create(['amount' => '100.00', 'repayment_deadline' => null]);

    $response = $this->actingAs(analyticsAs())->get(route('admin.analytics'))->assertOk();

    expect($response->viewData('overdueCustomerCount'))->toBe(1);
    expect(collect($response->viewData('agingBuckets'))->pluck('entry_count', 'key')->all())->toBe([
        'current' => 1,
        'overdue_1_7' => 0,
        'overdue_8_30' => 2,
        'overdue_over_30' => 0,
    ]);
});

it('compares stores using period totals and current balances without mixing stores', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    $busyStore = Store::factory()->create(['store_name' => 'Busy Store']);
    $quietStore = Store::factory()->create(['store_name' => 'Quiet Store']);
    $busyCustomer = Customer::factory()->for($busyStore)->create();
    $oldEntry = LedgerEntry::factory()->for($busyCustomer)->create(['amount' => '1000.00', 'transaction_date' => '2026-08-01', 'repayment_deadline' => '2026-09-01']);
    LedgerEntry::factory()->for($busyCustomer)->create(['amount' => '200.00', 'transaction_date' => '2026-10-10', 'repayment_deadline' => '2026-11-01']);
    Payment::factory()->for($busyCustomer)->for($oldEntry)->create(['amount' => '300.00', 'payment_date' => '2026-10-12']);
    Customer::factory()->count(2)->for($quietStore)->create();

    $response = $this->actingAs(analyticsAs())->get(route('admin.analytics', [
        'from' => '2026-10-01',
        'to' => '2026-10-16',
        'sort' => 'outstanding',
    ]))->assertOk();

    $rows = collect($response->viewData('storeComparison')->items())->map(fn (Store $store): array => [
        $store->store_name,
        $store->period_credit_total,
        $store->period_payment_total,
        $store->outstanding_balance,
        $store->overdue_balance,
        $store->customer_count,
    ])->all();

    expect($rows)->toBe([
        ['Busy Store', '200.00', '300.00', '900.00', '700.00', 1],
        ['Quiet Store', '0.00', '0.00', '0.00', '0.00', 2],
    ]);
});

it('rejects invalid analytics filters', function (array $query, string $errorField) {
    $this->actingAs(analyticsAs())
        ->from(route('admin.analytics'))
        ->get(route('admin.analytics', $query))
        ->assertSessionHasErrors($errorField);
})->with([
    'unknown interval' => [['interval' => 'hour'], 'interval'],
    'unknown sort' => [['sort' => 'profit'], 'sort'],
    'daily trends over 92 days' => [['from' => '2026-01-01', 'to' => '2026-06-30', 'interval' => 'day'], 'interval'],
    'period over two years' => [['from' => '2023-01-01', 'to' => '2026-01-01', 'interval' => 'month'], 'from'],
]);

it('shows empty states when there is no activity', function () {
    $this->actingAs(analyticsAs())
        ->get(route('admin.analytics'))
        ->assertOk()
        ->assertSee('No credits or payments were recorded in this period.')
        ->assertSee('No stores are registered yet.');
});

it('renders the trend chart with a legend and a data table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
    LedgerEntry::factory()->for(Customer::factory())->create(['amount' => '100.00', 'transaction_date' => '2026-10-10']);

    $this->actingAs(analyticsAs())
        ->get(route('admin.analytics'))
        ->assertOk()
        ->assertSee('Credits recorded')
        ->assertSee('Payments received')
        ->assertSee('Show data table')
        ->assertSee('Oct 10 · Credits ₱100.00 (1)', false);
});
