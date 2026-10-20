<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function monitoringRows(TestResponse $response): array
{
    return collect($response->viewData('transactions')->items())
        ->map(fn (object $row): string => $row->transaction_type.':'.$row->customer_name.':'.$row->amount)
        ->all();
}

it('redirects guests and forbids store owners', function () {
    $this->get(route('admin.transactions.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->withStore()->create())
        ->get(route('admin.transactions.index'))
        ->assertForbidden();
});

it('lists credits and payments from every store with their store names', function () {
    $firstStore = Store::factory()->create(['store_name' => 'First Store']);
    $secondStore = Store::factory()->create(['store_name' => 'Second Store']);
    $firstCustomer = Customer::factory()->for($firstStore)->create(['full_name' => 'Ana']);
    $secondCustomer = Customer::factory()->for($secondStore)->create(['full_name' => 'Ben']);
    $entry = LedgerEntry::factory()->for($firstCustomer)->create(['amount' => '500.00', 'transaction_date' => '2026-10-01']);
    LedgerEntry::factory()->for($secondCustomer)->create(['amount' => '300.00', 'transaction_date' => '2026-10-02']);
    Payment::factory()->for($firstCustomer)->for($entry)->create(['amount' => '120.00', 'payment_date' => '2026-10-03']);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.transactions.index'))
        ->assertOk()
        ->assertSee('First Store')
        ->assertSee('Second Store');

    expect(monitoringRows($response))->toBe([
        'payment:Ana:120.00',
        'credit:Ben:300.00',
        'credit:Ana:500.00',
    ]);
});

it('shows a payment split across several credits as one payment', function () {
    $customer = Customer::factory()->create(['full_name' => 'Ana']);
    $firstEntry = LedgerEntry::factory()->for($customer)->create(['amount' => '200.00', 'transaction_date' => '2026-09-01']);
    $secondEntry = LedgerEntry::factory()->for($customer)->create(['amount' => '300.00', 'transaction_date' => '2026-09-02']);
    Payment::factory()->for($customer)->for($firstEntry)->create(['amount' => '200.00', 'payment_batch_id' => 'batch-1', 'payment_date' => '2026-10-05']);
    Payment::factory()->for($customer)->for($secondEntry)->create(['amount' => '100.00', 'payment_batch_id' => 'batch-1', 'payment_date' => '2026-10-05']);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.transactions.index', ['type' => 'payment']));

    expect(monitoringRows($response))->toBe(['payment:Ana:300.00']);
    expect($response->viewData('totals'))->toBe([
        'transaction_count' => 1,
        'credit_total' => '0.00',
        'payment_total' => '300.00',
    ]);
});

it('filters transactions before pagination', function (array $query, array $expectedRows) {
    $store = Store::factory()->create(['store_name' => 'Filter Store']);
    $ana = Customer::factory()->for($store)->create(['full_name' => 'Ana Santos']);
    $ben = Customer::factory()->create(['full_name' => 'Ben Cruz']);
    $anaEntry = LedgerEntry::factory()->for($ana)->create(['amount' => '500.00', 'transaction_date' => '2026-10-01']);
    LedgerEntry::factory()->for($ben)->create(['amount' => '300.00', 'transaction_date' => '2026-10-10']);
    Payment::factory()->for($ana)->for($anaEntry)->create(['amount' => '50.00', 'payment_date' => '2026-10-10']);
    $query = array_map(fn (mixed $value) => $value === ':store' ? $store->id : $value, $query);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.transactions.index', $query))
        ->assertOk();

    expect(monitoringRows($response))->toBe($expectedRows);
})->with([
    'single inclusive day' => [['from' => '2026-10-10', 'to' => '2026-10-10'], ['credit:Ben Cruz:300.00', 'payment:Ana Santos:50.00']],
    'store' => [['store_id' => ':store'], ['payment:Ana Santos:50.00', 'credit:Ana Santos:500.00']],
    'credits only' => [['type' => 'credit'], ['credit:Ben Cruz:300.00', 'credit:Ana Santos:500.00']],
    'customer name' => [['customer' => 'ben'], ['credit:Ben Cruz:300.00']],
]);

it('sorts by amount and store with a stable order for ties', function () {
    $alpha = Store::factory()->create(['store_name' => 'Alpha Store']);
    $beta = Store::factory()->create(['store_name' => 'Beta Store']);
    LedgerEntry::factory()->for(Customer::factory()->for($beta)->state(['full_name' => 'Beta One']))->create(['amount' => '100.00']);
    LedgerEntry::factory()->for(Customer::factory()->for($alpha)->state(['full_name' => 'Alpha One']))->create(['amount' => '100.00']);
    LedgerEntry::factory()->for(Customer::factory()->for($alpha)->state(['full_name' => 'Alpha Two']))->create(['amount' => '900.00']);
    $superAdmin = User::factory()->superAdmin()->create();

    $byAmount = $this->actingAs($superAdmin)->get(route('admin.transactions.index', ['sort' => 'amount', 'direction' => 'asc']));
    $byAmountAgain = $this->get(route('admin.transactions.index', ['sort' => 'amount', 'direction' => 'asc']));
    $byStore = $this->get(route('admin.transactions.index', ['sort' => 'store', 'direction' => 'asc']));

    expect(monitoringRows($byAmount)[2])->toBe('credit:Alpha Two:900.00');
    expect(monitoringRows($byAmount))->toBe(monitoringRows($byAmountAgain));
    expect(collect($byStore->viewData('transactions')->items())->pluck('store_name')->all())
        ->toBe(['Alpha Store', 'Alpha Store', 'Beta Store']);
});

it('paginates on the server while totals cover every matching transaction', function () {
    $customer = Customer::factory()->create(['credit_limit' => '100000.00']);
    LedgerEntry::factory()->count(30)->for($customer)->create(['amount' => '10.00']);
    $superAdmin = User::factory()->superAdmin()->create();

    $firstPage = $this->actingAs($superAdmin)->get(route('admin.transactions.index'));
    $secondPage = $this->get(route('admin.transactions.index', ['page' => 2]));

    expect($firstPage->viewData('transactions')->count())->toBe(25);
    expect($secondPage->viewData('transactions')->count())->toBe(5);
    expect($firstPage->viewData('transactions')->total())->toBe(30);
    expect($secondPage->viewData('totals'))
        ->transaction_count->toBe(30)
        ->credit_total->toBe('300.00');
});

it('shows the current status of each credit', function () {
    $this->travelTo('2026-10-16 09:00:00');
    $customer = Customer::factory()->create();
    $paidEntry = LedgerEntry::factory()->for($customer)->create(['amount' => '100.00', 'repayment_deadline' => '2026-10-01', 'transaction_date' => '2026-09-01']);
    LedgerEntry::factory()->for($customer)->create(['amount' => '200.00', 'repayment_deadline' => '2026-10-10', 'transaction_date' => '2026-09-02']);
    LedgerEntry::factory()->for($customer)->create(['amount' => '300.00', 'repayment_deadline' => '2026-10-30', 'transaction_date' => '2026-09-03']);
    Payment::factory()->for($customer)->for($paidEntry)->create(['amount' => '100.00']);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.transactions.index', ['type' => 'credit', 'sort' => 'amount', 'direction' => 'asc']));

    expect(collect($response->viewData('transactions')->items())->map(fn (object $row) => [$row->status, $row->remaining_amount])->all())
        ->toBe([['Paid', '0.00'], ['Overdue', '200.00'], ['Unpaid', '300.00']]);
});

it('rejects filter and sort values outside the allowlist', function (array $query) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.transactions.index'))
        ->get(route('admin.transactions.index', $query))
        ->assertSessionHasErrors(array_keys($query));
})->with([
    'sql in sort' => [['sort' => 'amount desc, (select 1)']],
    'unknown direction' => [['direction' => 'up']],
    'unknown type' => [['type' => 'refund']],
    'invalid date' => [['from' => '10/01/2026']],
]);

it('offers no way to change transactions from the monitoring page', function (string $method) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->call($method, route('admin.transactions.index'))
        ->assertMethodNotAllowed();
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);
