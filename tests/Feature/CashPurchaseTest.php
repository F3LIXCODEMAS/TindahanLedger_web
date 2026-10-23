<?php

use App\Exceptions\StoreSuspended;
use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\SukiPoint;
use App\Models\SukiPointTransaction;
use App\Models\User;
use App\Services\CashPurchaseService;
use App\Services\FinancialSummaryService;
use App\Services\LedgerService;
use App\Support\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Valid Record Cash Purchase input: one Rice 1kg at ₱60.00 × 0.25 and one
 * Coke at ₱65.00 × 2, total ₱145.00. `$overrides` replace or add fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cashPurchasePayload(Store $store, array $overrides = []): array
{
    $rice = Product::factory()->for($store)->create(['name' => 'Rice 1kg', 'unit_price' => '60.00']);
    $coke = Product::factory()->for($store)->create(['name' => 'Coke 1.5L', 'unit_price' => '65.00']);

    return [
        'idempotency_key' => (string) Str::uuid(),
        'items' => [
            ['product_id' => (string) $rice->id, 'quantity' => '0.25', 'unit_price' => '60.00'],
            ['product_id' => (string) $coke->id, 'quantity' => '2', 'unit_price' => '65.00'],
        ],
        'expected_total' => '145.00',
        ...$overrides,
    ];
}

function expectNoCashPurchaseRecorded(): void
{
    expect(Purchase::query()->count())->toBe(0);
    expect(PurchaseItem::query()->count())->toBe(0);
}

describe('recording a cash purchase', function () {
    it('records a walk-in sale with snapshots and a server-calculated total', function () {
        $store = Store::factory()->create();
        $customerCount = Customer::query()->count();
        $payload = cashPurchasePayload($store);

        $response = $this->actingAs($store->owner)->post(route('cash-purchases.store'), $payload);

        $purchase = Purchase::query()->sole();
        $response->assertRedirect(route('cash-purchases.show', $purchase))
            ->assertSessionHas('status', 'Cash purchase recorded.');
        expect($purchase)
            ->store_id->toBe($store->id)
            ->customer_id->toBeNull()
            ->ledger_entry_id->toBeNull()
            ->purchase_type->toBe(Purchase::TYPE_CASH)
            ->recorded_by_user_id->toBe($store->owner->id)
            ->idempotency_key->toBe($payload['idempotency_key']);
        expect($purchase->purchase_date->toDateString())->toBe(now()->toDateString());
        expect($purchase->items->map->only(['store_id', 'product_name_snapshot', 'quantity', 'unit_price_snapshot', 'line_total'])->all())->toBe([
            ['store_id' => $store->id, 'product_name_snapshot' => 'Rice 1kg', 'quantity' => '0.250', 'unit_price_snapshot' => '60.00', 'line_total' => '15.00'],
            ['store_id' => $store->id, 'product_name_snapshot' => 'Coke 1.5L', 'quantity' => '2.000', 'unit_price_snapshot' => '65.00', 'line_total' => '130.00'],
        ]);
        expect($purchase->referenceSubtotal())->toBe('145.00');
        expect(Customer::query()->count())->toBe($customerCount);
        expect(LedgerEntry::query()->count())->toBe(0);
        expect(Payment::query()->count())->toBe(0);
    });

    it('links the sale to a customer of the same store', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create();

        $this->actingAs($store->owner)
            ->post(route('cash-purchases.store'), cashPurchasePayload($store, ['customer_id' => (string) $customer->id]))
            ->assertSessionHasNoErrors();

        expect(Purchase::query()->sole()->customer_id)->toBe($customer->id);
    });

    it('saves a sale-only price without changing the catalog price', function (?string $catalogPrice, string $enteredPrice, string $expectedLineTotal) {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => $catalogPrice]);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '2', 'unit_price' => $enteredPrice]],
            'expected_total' => $expectedLineTotal,
        ])->assertSessionHasNoErrors();

        expect(PurchaseItem::query()->sole()->only(['unit_price_snapshot', 'line_total']))
            ->toBe(['unit_price_snapshot' => $enteredPrice, 'line_total' => $expectedLineTotal]);
        expect($product->fresh()->unit_price)->toBe($catalogPrice);
    })->with([
        'product without a catalog price' => [null, '12.50', '25.00'],
        'catalog price overridden for this sale' => ['65.00', '60.00', '120.00'],
    ]);

    it('uses the catalog price when no sale price is submitted', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '7.50']);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1.5']],
            'expected_total' => '11.25',
        ])->assertSessionHasNoErrors();

        expect(PurchaseItem::query()->sole()->only(['unit_price_snapshot', 'line_total']))
            ->toBe(['unit_price_snapshot' => '7.50', 'line_total' => '11.25']);
    });

    it('keeps saved snapshots when the product is later renamed or repriced', function () {
        $store = Store::factory()->create();
        $this->actingAs($store->owner)->post(route('cash-purchases.store'), cashPurchasePayload($store));

        Product::query()->where('name', 'Rice 1kg')->sole()->update(['name' => 'Premium Rice', 'unit_price' => '90.00']);

        expect(PurchaseItem::query()->where('product_name_snapshot', 'Rice 1kg')->sole()->only(['unit_price_snapshot', 'line_total']))
            ->toBe(['unit_price_snapshot' => '60.00', 'line_total' => '15.00']);
    });
});

describe('validation and tenant isolation', function () {
    it('rejects products that are not active in the owner store', function (Closure $makeProduct) {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $makeProduct($store)->id, 'quantity' => '1', 'unit_price' => '10.00']],
            'expected_total' => '10.00',
        ])->assertSessionHasErrors(['items.0.product_id' => 'One or more selected products are not available in your active catalog. Remove them and try again.']);

        expectNoCashPurchaseRecorded();
    })->with([
        'another store product' => fn (Store $store) => Product::factory()->create(['unit_price' => '10.00']),
        'inactive product' => fn (Store $store) => Product::factory()->for($store)->inactive()->create(['unit_price' => '10.00']),
    ]);

    it('rejects a customer that is not in the owner store', function (Closure $customerId) {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)
            ->post(route('cash-purchases.store'), cashPurchasePayload($store, ['customer_id' => $customerId()]))
            ->assertSessionHasErrors('customer_id');

        expectNoCashPurchaseRecorded();
    })->with([
        'another store customer' => fn () => (string) Customer::factory()->create()->id,
        'unknown customer' => fn () => '999999',
        'not a number' => fn () => 'walk-in',
    ]);

    it('rejects an invalid quantity without rounding it', function (?string $quantity, string $message) {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);
        $item = ['product_id' => (string) $product->id, 'unit_price' => '10.00'];

        if ($quantity !== null) {
            $item['quantity'] = $quantity;
        }

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [$item],
            'expected_total' => '10.00',
        ])->assertSessionHasErrors(['items.0.quantity' => $message]);

        expectNoCashPurchaseRecorded();
    })->with([
        'zero' => ['0', Quantity::FORMAT_MESSAGE],
        'negative' => ['-1', Quantity::FORMAT_MESSAGE],
        'double decimal point' => ['1..5', Quantity::FORMAT_MESSAGE],
        'letters' => ['abc', Quantity::FORMAT_MESSAGE],
        'four decimal places' => ['0.1255', Quantity::FORMAT_MESSAGE],
        'above the maximum' => ['1000.001', Quantity::MAXIMUM_MESSAGE],
        'missing' => [null, 'Enter a quantity for each selected product.'],
    ]);

    it('rejects an invalid sale price', function (string $unitPrice) {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1', 'unit_price' => $unitPrice]],
            'expected_total' => '10.00',
        ])->assertSessionHasErrors(['items.0.unit_price' => 'Enter a price of zero or more with no more than two decimal places.']);

        expectNoCashPurchaseRecorded();
    })->with([
        'negative' => ['-1.00'],
        'three decimal places' => ['1.234'],
        'letters' => ['ten'],
    ]);

    it('requires a price for a product without a catalog price', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['name' => 'Bar Soap', 'unit_price' => null]);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
            'expected_total' => '0.00',
        ])->assertSessionHasErrors(['items' => 'Enter a price for Bar Soap; it has no catalog price.']);

        expectNoCashPurchaseRecorded();
    });

    it('rejects a total that does not match the items', function () {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)
            ->post(route('cash-purchases.store'), cashPurchasePayload($store, ['expected_total' => '1.00']))
            ->assertSessionHasErrors(['expected_total' => 'The total is now ₱145.00. Review the items and prices, then record the purchase again.']);

        expectNoCashPurchaseRecorded();
    });

    it('rejects a total that went stale because the catalog price changed', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);
        $product->update(['unit_price' => '12.00']);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
            'expected_total' => '10.00',
        ])->assertSessionHasErrors(['expected_total' => 'The total is now ₱12.00. Review the items and prices, then record the purchase again.']);

        expectNoCashPurchaseRecorded();
    });

    it('requires products, a reviewed total, and a positive total', function (Closure $payload, array $expectedErrors) {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)
            ->post(route('cash-purchases.store'), $payload($store))
            ->assertSessionHasErrors($expectedErrors);

        expectNoCashPurchaseRecorded();
    })->with([
        'no products' => [
            fn (Store $store) => ['idempotency_key' => (string) Str::uuid(), 'expected_total' => '0.00'],
            ['items' => 'Add at least one product to the cash purchase.'],
        ],
        'no reviewed total' => [
            fn (Store $store) => collect(cashPurchasePayload($store))->except('expected_total')->all(),
            ['expected_total' => 'Review the total before recording the cash purchase.'],
        ],
        'zero total' => [
            fn (Store $store) => [
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => (string) Product::factory()->for($store)->create()->id, 'quantity' => '1', 'unit_price' => '0']],
                'expected_total' => '0.00',
            ],
            ['items' => 'The cash purchase total must be greater than zero.'],
        ],
    ]);

    it('blocks a suspended store from recording a cash purchase', function () {
        $store = Store::factory()->suspended()->create();

        $this->actingAs($store->owner)->get(route('cash-purchases.create'))->assertRedirect(route('dashboard'));
        $this->post(route('cash-purchases.store'), cashPurchasePayload($store))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('store');

        expectNoCashPurchaseRecorded();
    });

    it('rejects a sale in the service once the store is suspended', function () {
        $store = Store::factory()->suspended()->create();
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);

        expect(fn () => app(CashPurchaseService::class)->record($store, $store->owner, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => '1']],
            'expected_total' => '10.00',
        ]))->toThrow(StoreSuspended::class);

        expectNoCashPurchaseRecorded();
    });

    it('redirects guests and forbids the super admin', function () {
        $this->get(route('cash-purchases.create'))->assertRedirect(route('login'));
        $this->post(route('cash-purchases.store'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cash-purchases.create'))
            ->assertForbidden();
    });

    it('responds as not found for another store purchase or a credit purchase', function (Closure $makePurchase) {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)->get(route('cash-purchases.show', $makePurchase($owner)))->assertNotFound();
    })->with([
        'another store cash purchase' => fn (User $owner) => Purchase::factory()->cash()->create(),
        'own utang purchase' => fn (User $owner) => Purchase::factory()->create([
            'ledger_entry_id' => LedgerEntry::factory()->for(Customer::factory()->for($owner->store))->create()->id,
        ]),
    ]);
});

describe('atomicity and idempotency', function () {
    it('rolls back the purchase and its items when an item cannot be saved', function () {
        $store = Store::factory()->create();
        $payload = cashPurchasePayload($store);
        PurchaseItem::creating(function (): void {
            throw new RuntimeException('Simulated item failure.');
        });

        $this->actingAs($store->owner)
            ->from(route('cash-purchases.create'))
            ->post(route('cash-purchases.store'), $payload)
            ->assertRedirect(route('cash-purchases.create'))
            ->assertSessionHasErrors(['items' => 'Unable to record the cash purchase. Please try again.']);

        expectNoCashPurchaseRecorded();
    });

    it('returns the original sale when the same submission is retried', function () {
        $store = Store::factory()->create();
        $payload = cashPurchasePayload($store);

        $this->actingAs($store->owner)->post(route('cash-purchases.store'), $payload);
        $purchase = Purchase::query()->sole();
        $this->post(route('cash-purchases.store'), $payload)->assertRedirect(route('cash-purchases.show', $purchase));

        expect(Purchase::query()->count())->toBe(1);
        expect(PurchaseItem::query()->count())->toBe(2);
        expect(app(CashPurchaseService::class)->record($store, $store->owner, [...$payload, 'items' => []])->is($purchase))->toBeTrue();
    });

    it('enforces one idempotency key per store and same-store links in the database', function (Closure $insertInvalidRow) {
        $purchase = Purchase::factory()->cash()->create();

        expect(fn () => $insertInvalidRow($purchase))->toThrow(QueryException::class);
    })->with([
        'reused idempotency key in the same store' => fn (Purchase $purchase) => Purchase::factory()->cash()->create([
            'store_id' => $purchase->store_id,
            'idempotency_key' => $purchase->idempotency_key,
        ]),
        'customer from another store' => fn (Purchase $purchase) => DB::table('purchases')->insert([
            'store_id' => $purchase->store_id,
            'customer_id' => Customer::factory()->create()->id,
            'purchase_type' => Purchase::TYPE_CASH,
            'purchase_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]),
    ]);

    it('allows the same idempotency key in different stores', function () {
        $purchase = Purchase::factory()->cash()->create();

        expect(Purchase::factory()->cash()->create(['idempotency_key' => $purchase->idempotency_key])->exists)->toBeTrue();
    });
});

describe('separation from debt and repayments', function () {
    it('leaves balances, payments, overdue status, Suki Points, and dashboard totals unchanged', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $ledgerService = app(LedgerService::class);
        $ledgerService->recordCredit($customer, [
            'amount' => '300.00',
            'transaction_date' => now()->subDays(20)->toDateString(),
            'repayment_deadline' => now()->subDays(5)->toDateString(),
        ]);
        $ledgerService->recordCustomerPayment($customer, ['amount' => '120.00', 'idempotency_key' => (string) Str::uuid()]);
        $financialSummary = app(FinancialSummaryService::class);
        $snapshot = fn (): array => [
            'store_totals' => $financialSummary->forStore($store),
            'customer_totals' => $financialSummary->forCustomer($customer->fresh()),
            'available_credit' => $ledgerService->financialSummary($customer->fresh()),
            'current_balance' => $customer->fresh()->current_balance,
            'ledger_entries' => LedgerEntry::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::query()->orderBy('id')->get()->toArray(),
            'suki_points' => SukiPoint::query()->get()->toArray(),
            'suki_point_transactions' => SukiPointTransaction::query()->count(),
            'debt_cycles' => DebtCycleCompletion::query()->count(),
        ];
        $before = $snapshot();

        $this->actingAs($store->owner)
            ->post(route('cash-purchases.store'), cashPurchasePayload($store, ['customer_id' => (string) $customer->id]))
            ->assertSessionHasNoErrors();

        expect(Purchase::query()->sole()->isCash())->toBeTrue();
        expect($snapshot())->toBe($before);
        expect($before['store_totals']['overdue_account_count'])->toBe(1);
        expect($before['current_balance'])->toBe('180.00');
    });
});

describe('pages', function () {
    it('shows the cash purchase form with walk-in as the default and only own customers', function () {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['full_name' => 'Own Customer']);
        Customer::factory()->create(['full_name' => 'Other Store Customer']);

        $this->actingAs($store->owner)->get(route('cash-purchases.create'))
            ->assertOk()
            ->assertSee('Record Cash Purchase')
            ->assertSee('Walk-in customer (no record)')
            ->assertSee('Own Customer')
            ->assertDontSee('Other Store Customer')
            ->assertSee('data-mode="cash"', escape: false)
            ->assertSee('name="expected_total"', escape: false);
    });

    it('keeps the entered quantity and sale price after a validation error', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['name' => 'Bar Soap', 'unit_price' => null]);

        $this->actingAs($store->owner)
            ->from(route('cash-purchases.create'))
            ->followingRedirects()
            ->post(route('cash-purchases.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => (string) $product->id, 'quantity' => '0.5', 'unit_price' => '24.00']],
                'expected_total' => '1.00',
            ])
            ->assertOk()
            ->assertSee('Bar Soap')
            ->assertSee('value="0.5"', escape: false)
            ->assertSee('value="24.00"', escape: false);
    });

    it('shows the recorded items and total on the confirmation page', function () {
        $store = Store::factory()->create();
        $this->actingAs($store->owner)->post(route('cash-purchases.store'), cashPurchasePayload($store));

        $this->get(route('cash-purchases.show', Purchase::query()->sole()))
            ->assertOk()
            ->assertSee('Cash Purchase Recorded')
            ->assertSee('Walk-in customer')
            ->assertSee('₱145.00')
            ->assertSee('Rice 1kg × 0.25 @ ₱60.00 = ₱15.00');
    });

    it('links to Record Cash Purchase from the dashboard', function () {
        $store = Store::factory()->create();

        $this->actingAs($store->owner)->get(route('dashboard'))
            ->assertSee(route('cash-purchases.create'), escape: false);
    });
});
