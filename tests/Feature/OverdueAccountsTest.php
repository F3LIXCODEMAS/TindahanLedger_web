<?php

use App\Models\Customer;
use App\Models\EmailReminderLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

it('redirects guests from the overdue accounts page', function () {
    $this->get(route('overdue-accounts.index'))
        ->assertRedirect(route('login'));
});

it('shows a clear empty state when no account is overdue', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->for($store)->create();
    LedgerEntry::factory()->for($customer)->create([
        'amount' => '100.00',
        'repayment_deadline' => '2026-10-15',
    ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'))
        ->assertOk()
        ->assertSeeText('No customers have overdue balances.');
});

it('lists only overdue entries with unpaid balances', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->for($store)->create([
        'full_name' => 'Marisol Santos',
        'contact_number' => '09171234567',
    ]);

    $overdueEntry = LedgerEntry::factory()->for($customer)->create([
        'amount' => '1000.00',
        'itemized_list' => ['Rice', 'Coffee'],
        'note' => 'Family purchase',
        'transaction_date' => '2026-09-20',
        'repayment_deadline' => '2026-10-10',
    ]);
    Payment::factory()->for($customer)->for($overdueEntry)->create(['amount' => '300.00']);

    $paidEntry = LedgerEntry::factory()->for($customer)->create([
        'amount' => '400.00',
        'itemized_list' => ['Paid goods'],
        'repayment_deadline' => '2026-10-01',
    ]);
    Payment::factory()->for($customer)->for($paidEntry)->create(['amount' => '400.00']);

    LedgerEntry::factory()->for($customer)->create([
        'amount' => '100.00',
        'itemized_list' => ['Due today'],
        'repayment_deadline' => '2026-10-15',
    ]);
    LedgerEntry::factory()->for($customer)->create([
        'amount' => '200.00',
        'itemized_list' => ['Not due yet'],
        'repayment_deadline' => '2026-10-20',
    ]);

    $response = $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'));

    $response->assertOk()
        ->assertSeeText('Marisol Santos')
        ->assertSeeText('09171234567')
        ->assertSeeText('₱1000.00')
        ->assertSeeText('₱700.00 unpaid')
        ->assertSeeText('Rice, Coffee')
        ->assertSeeText('Family purchase')
        ->assertSeeText('Oct 10, 2026')
        ->assertSeeText('5 days')
        ->assertSee(route('customers.show', $customer), false)
        ->assertSeeText('No reminder history available.')
        ->assertDontSeeText('Paid goods')
        ->assertDontSeeText('Due today')
        ->assertDontSeeText('Not due yet');
});

it('escapes customer names in the overdue account list', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->for($store)->create([
        'full_name' => 'Luz <script>alert(1)</script>',
    ]);
    LedgerEntry::factory()->for($customer)->create([
        'amount' => '100.00',
        'repayment_deadline' => '2026-10-01',
    ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('shows each overdue entry once with its persisted reminder attempts', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    $customer = Customer::factory()->for($store)->create(['full_name' => 'Liza Reyes']);
    $olderEntry = LedgerEntry::factory()->for($customer)->create([
        'amount' => '500.00',
        'itemized_list' => ['Flour'],
        'repayment_deadline' => '2026-10-01',
    ]);
    Payment::factory()->for($customer)->for($olderEntry)->create(['amount' => '100.00']);
    $newerEntry = LedgerEntry::factory()->for($customer)->create([
        'amount' => '300.00',
        'itemized_list' => ['Cooking oil'],
        'repayment_deadline' => '2026-10-10',
    ]);

    EmailReminderLog::factory()->forLedgerEntry($olderEntry)->create([
        'reminder_type' => EmailReminderLog::TYPE_OVERDUE,
        'scheduled_reminder_date' => '2026-10-02',
        'delivery_status' => EmailReminderLog::STATUS_FAILED,
        'attempted_at' => CarbonImmutable::parse('2026-10-02 08:15:00', 'Asia/Manila'),
        'sent_at' => null,
        'failure_reason' => 'email_delivery_failed',
    ]);
    EmailReminderLog::factory()->forLedgerEntry($newerEntry)->create([
        'reminder_type' => EmailReminderLog::TYPE_DUE_DATE,
        'scheduled_reminder_date' => '2026-10-10',
        'delivery_status' => EmailReminderLog::STATUS_SENT,
        'attempted_at' => CarbonImmutable::parse('2026-10-10 08:15:00', 'Asia/Manila'),
        'sent_at' => CarbonImmutable::parse('2026-10-10 08:16:00', 'Asia/Manila'),
        'failure_reason' => null,
    ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'))
        ->assertOk()
        ->assertSeeText('Liza Reyes')
        ->assertSeeText('₱700.00')
        ->assertSeeText('Flour')
        ->assertSeeText('Cooking oil')
        ->assertSeeText('Overdue reminder')
        ->assertSeeText('Due-date reminder')
        ->assertSeeText('Failed')
        ->assertSeeText('Sent')
        ->assertSeeText('Email delivery failed. It will be retried on a future run.')
        ->assertSeeText('Sent Oct 10, 2026 8:16 AM.')
        ->assertViewHas('customers', function ($customers) use ($customer): bool {
            return $customers->total() === 1
                && $customers->first()->is($customer)
                && $customers->first()->ledgerEntries->count() === 2;
        });
});

it('sorts overdue customers by longest overdue and then by highest outstanding balance', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    $oldestLowBalance = Customer::factory()->for($store)->create(['full_name' => 'Ana Low']);
    $oldestHighBalance = Customer::factory()->for($store)->create(['full_name' => 'Belen High']);
    $newerHighestBalance = Customer::factory()->for($store)->create(['full_name' => 'Cora New']);

    LedgerEntry::factory()->for($oldestLowBalance)->create([
        'amount' => '100.00',
        'repayment_deadline' => '2026-10-01',
    ]);
    LedgerEntry::factory()->for($oldestHighBalance)->create([
        'amount' => '300.00',
        'repayment_deadline' => '2026-10-01',
    ]);
    LedgerEntry::factory()->for($newerHighestBalance)->create([
        'amount' => '1000.00',
        'repayment_deadline' => '2026-10-10',
    ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'))
        ->assertOk()
        ->assertViewHas('customers', fn ($customers): bool => $customers->getCollection()
            ->pluck('id')
            ->all() === [
                $oldestHighBalance->id,
                $oldestLowBalance->id,
                $newerHighestBalance->id,
            ]);

    $this->get(route('overdue-accounts.index', ['sort' => 'balance']))
        ->assertOk()
        ->assertViewHas('customers', fn ($customers): bool => $customers->getCollection()
            ->pluck('id')
            ->all() === [
                $newerHighestBalance->id,
                $oldestHighBalance->id,
                $oldestLowBalance->id,
            ]);
});

it('filters by customer name and retains selected controls', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Manila'));
    Customer::factory()->for($store)->create(['full_name' => 'Maria Dela Cruz'])
        ->ledgerEntries()->create([
            'amount' => '100.00',
            'itemized_list' => ['Rice'],
            'transaction_date' => '2026-09-01',
            'repayment_deadline' => '2026-10-01',
            'running_balance' => '100.00',
        ]);
    Customer::factory()->for($store)->create(['full_name' => 'Pedro Santos'])
        ->ledgerEntries()->create([
            'amount' => '200.00',
            'itemized_list' => ['Sugar'],
            'transaction_date' => '2026-09-01',
            'repayment_deadline' => '2026-10-01',
            'running_balance' => '200.00',
        ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index', ['search' => 'maria', 'sort' => 'balance']))
        ->assertOk()
        ->assertSeeText('Maria Dela Cruz')
        ->assertDontSeeText('Pedro Santos')
        ->assertViewHas('search', 'maria')
        ->assertViewHas('sort', 'balance');
});

it('uses the configured timezone for the overdue date boundary', function () {
    $store = Store::factory()->create();
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 16:30:00', 'UTC'));
    $customer = Customer::factory()->for($store)->create(['full_name' => 'Boundary Customer']);
    $yesterdayDeadline = LedgerEntry::factory()->for($customer)->create([
        'amount' => '100.00',
        'repayment_deadline' => '2026-10-14',
    ]);
    LedgerEntry::factory()->for($customer)->create([
        'amount' => '200.00',
        'itemized_list' => ['Due locally today'],
        'repayment_deadline' => '2026-10-15',
    ]);

    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index'))
        ->assertOk()
        ->assertSeeText('Boundary Customer')
        ->assertViewHas('customers', function ($customers) use ($yesterdayDeadline): bool {
            $entries = $customers->first()->ledgerEntries;

            return $entries->count() === 1
                && $entries->first()->is($yesterdayDeadline)
                && $entries->first()->days_overdue === 1;
        });
});

it('rejects unsupported sorting options', function () {
    $store = Store::factory()->create();
    $this->actingAs($store->owner)
        ->get(route('overdue-accounts.index', ['sort' => 'full_name']))
        ->assertSessionHasErrors('sort');
});
