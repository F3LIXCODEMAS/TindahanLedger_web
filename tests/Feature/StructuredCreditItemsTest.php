<?php

use App\Exceptions\InvalidPurchaseItems;
use App\Mail\CreditEntryReceipt;
use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use App\Models\SukiPoint;
use App\Services\LedgerService;
use App\Support\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Valid credit form input for the given customer; `$overrides` replace or
 * add fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function creditPayload(Customer $customer, array $overrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'amount' => '250.00',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/**
 * Asserts that no ledger, purchase, or balance change was recorded.
 */
function expectNothingRecorded(Customer $customer): void
{
    expect(LedgerEntry::query()->count())->toBe(0);
    expect(Purchase::query()->count())->toBe(0);
    expect(PurchaseItem::query()->count())->toBe(0);
    expect($customer->fresh()->current_balance)->toBe('0.00');
}

describe('recording structured items', function () {
    it('saves product snapshots, decimal quantities, and line totals while the entered amount stays authoritative', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $rice = Product::factory()->for($store)->create(['name' => 'Rice 1kg', 'unit_price' => '60.00']);
        $cannedGoods = Product::factory()->for($store)->create(['name' => 'Canned Goods', 'unit_price' => '35.00']);
        $soap = Product::factory()->for($store)->create(['name' => 'Bar Soap', 'unit_price' => null]);

        $response = $this->actingAs($store->owner)->post(route('credits.store'), creditPayload($customer, [
            'amount' => '250.00',
            'itemized_list' => 'candies',
            'items' => [
                ['product_id' => (string) $rice->id, 'quantity' => '0.25'],
                ['product_id' => (string) $cannedGoods->id, 'quantity' => '2'],
                ['product_id' => (string) $soap->id, 'quantity' => '1.5'],
            ],
        ]));

        $entry = LedgerEntry::query()->sole();
        $response->assertRedirect(route('credits.confirmation', $entry));
        expect($entry->amount)->toBe('250.00');
        expect($entry->running_balance)->toBe('250.00');
        expect($entry->itemized_list)->toBe(['candies']);
        expect($customer->fresh()->current_balance)->toBe('250.00');

        $purchase = Purchase::query()->sole();
        expect($purchase)
            ->store_id->toBe($store->id)
            ->customer_id->toBe($customer->id)
            ->ledger_entry_id->toBe($entry->id)
            ->purchase_type->toBe(Purchase::TYPE_UTANG)
            ->recorded_by_user_id->toBe($store->owner->id);
        expect($purchase->purchase_date->toDateString())->toBe($entry->transaction_date->toDateString());

        $items = $purchase->items->map->only(['store_id', 'product_id', 'product_name_snapshot', 'quantity', 'unit_price_snapshot', 'line_total'])->all();
        expect($items)->toBe([
            ['store_id' => $store->id, 'product_id' => $rice->id, 'product_name_snapshot' => 'Rice 1kg', 'quantity' => '0.250', 'unit_price_snapshot' => '60.00', 'line_total' => '15.00'],
            ['store_id' => $store->id, 'product_id' => $cannedGoods->id, 'product_name_snapshot' => 'Canned Goods', 'quantity' => '2.000', 'unit_price_snapshot' => '35.00', 'line_total' => '70.00'],
            ['store_id' => $store->id, 'product_id' => $soap->id, 'product_name_snapshot' => 'Bar Soap', 'quantity' => '1.500', 'unit_price_snapshot' => null, 'line_total' => null],
        ]);
        expect($purchase->referenceSubtotal())->toBeNull();
    });

    it('accepts whole and fractional quantities and stores them without rounding', function (string $quantity, string $storedQuantity) {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => [['product_id' => (string) $product->id, 'quantity' => $quantity]],
            ]))
            ->assertSessionHasNoErrors();

        expect(PurchaseItem::query()->sole()->quantity)->toBe($storedQuantity);
        expect(DB::table('purchase_items')->value('quantity'))->toEqual($storedQuantity);
    })->with([
        'one' => ['1', '1.000'],
        'two' => ['2', '2.000'],
        'half' => ['0.5', '0.500'],
        'quarter' => ['0.25', '0.250'],
        'one and a half' => ['1.5', '1.500'],
        'two and three quarters' => ['2.75', '2.750'],
        'three decimal places' => ['0.125', '0.125'],
        'smallest step' => ['0.001', '0.001'],
        'maximum' => ['1000', '1000.000'],
    ]);

    it('rejects an invalid quantity without recording anything', function (?string $quantity, string $message) {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create();
        $item = ['product_id' => (string) $product->id];

        if ($quantity !== null) {
            $item['quantity'] = $quantity;
        }

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, ['items' => [$item]]))
            ->assertSessionHasErrors(['items.0.quantity' => $message]);

        expectNothingRecorded($customer);
    })->with([
        'zero' => ['0', Quantity::FORMAT_MESSAGE],
        'zero with decimals' => ['0.000', Quantity::FORMAT_MESSAGE],
        'negative' => ['-1', Quantity::FORMAT_MESSAGE],
        'double decimal point' => ['1..5', Quantity::FORMAT_MESSAGE],
        'letters' => ['two', Quantity::FORMAT_MESSAGE],
        'infinity' => ['INF', Quantity::FORMAT_MESSAGE],
        'not a number' => ['NAN', Quantity::FORMAT_MESSAGE],
        'exponent' => ['1e2', Quantity::FORMAT_MESSAGE],
        'four decimal places is not rounded' => ['0.1255', Quantity::FORMAT_MESSAGE],
        'above the maximum' => ['1000.001', Quantity::MAXIMUM_MESSAGE],
        'missing' => [null, 'Enter a quantity for each selected product.'],
        'empty' => ['', 'Enter a quantity for each selected product.'],
    ]);

    it('rejects a product chosen twice', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create();

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, ['items' => [
                ['product_id' => (string) $product->id, 'quantity' => '1'],
                ['product_id' => (string) $product->id, 'quantity' => '2'],
            ]]))
            ->assertSessionHasErrors(['items.0.product_id' => 'Each product can only be added once.']);

        expectNothingRecorded($customer);
    });

    it('rejects products that are not active in the owner store', function (Closure $makeProduct) {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => [['product_id' => (string) $makeProduct($store)->id, 'quantity' => '1']],
            ]))
            ->assertSessionHasErrors(['items.0.product_id' => 'One or more selected products are not available in your active catalog. Remove them and try again.']);

        expectNothingRecorded($customer);
    })->with([
        'another store product' => fn (Store $store) => Product::factory()->create(),
        'inactive product' => fn (Store $store) => Product::factory()->for($store)->inactive()->create(),
    ]);

    it('rejects more than fifty products', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $products = Product::factory()->for($store)->count(51)->create();

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => $products->map(fn (Product $product): array => ['product_id' => (string) $product->id, 'quantity' => '1'])->all(),
            ]))
            ->assertSessionHasErrors(['items' => 'A credit can list at most 50 products.']);

        expectNothingRecorded($customer);
    });

    it('records a credit without structured items exactly as before', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, ['itemized_list' => 'rice, sardines']))
            ->assertSessionHasNoErrors();

        expect(LedgerEntry::query()->sole()->itemized_list)->toBe(['rice, sardines']);
        expect(Purchase::query()->count())->toBe(0);
    });

    it('keeps saved snapshots when the product is later renamed, repriced, or deactivated', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['name' => 'Rice 1kg', 'unit_price' => '60.00']);
        $this->actingAs($store->owner)->post(route('credits.store'), creditPayload($customer, [
            'items' => [['product_id' => (string) $product->id, 'quantity' => '0.25']],
        ]));

        $product->update(['name' => 'Premium Rice', 'unit_price' => '80.00']);
        $product->forceFill(['status' => Product::STATUS_INACTIVE])->save();

        expect(PurchaseItem::query()->sole()->only(['product_name_snapshot', 'unit_price_snapshot', 'line_total']))
            ->toBe(['product_name_snapshot' => 'Rice 1kg', 'unit_price_snapshot' => '60.00', 'line_total' => '15.00']);
        $this->get(route('customers.show', $customer))
            ->assertSee('Rice 1kg × 0.25 @ ₱60.00 = ₱15.00')
            ->assertDontSee('Premium Rice');
    });
});

describe('financial integrity', function () {
    it('still enforces the credit limit and records nothing when items are included', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '100.00']);
        $product = Product::factory()->for($store)->create(['unit_price' => '50.00']);

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, [
                'amount' => '150.00',
                'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
            ]))
            ->assertSessionHasErrors('amount');

        expectNothingRecorded($customer);
    });

    it('blocks a suspended store from recording a credit with items', function () {
        $store = Store::factory()->suspended()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create();

        $this->actingAs($store->owner)
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
            ]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('store');

        expectNothingRecorded($customer);
    });

    it('copies the late payment policy onto a credit with items', function () {
        $store = Store::factory()->create();
        $policy = StorePenaltyPolicy::factory()->for($store)->create(['fee_cents' => 2500, 'grace_days' => 5]);
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create();

        $this->actingAs($store->owner)->post(route('credits.store'), creditPayload($customer, [
            'repayment_deadline' => now()->addWeek()->toDateString(),
            'penalty_policy_acknowledged' => '1',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
        ]))->assertSessionHasNoErrors();

        expect(collect(LedgerEntry::query()->sole()->penalty_policy_snapshot)->only(['policy_id', 'fee_cents', 'grace_days'])->all())
            ->toBe(['policy_id' => $policy->id, 'fee_cents' => 2500, 'grace_days' => 5]);
    });

    it('leaves payment allocation and Suki Points unchanged by structured items', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['unit_price' => '10.00']);
        $ledgerService = app(LedgerService::class);
        $entry = $ledgerService->recordCredit($customer, [
            'amount' => '250.00',
            'items' => [['product_id' => $product->id, 'quantity' => '3']],
        ]);

        $ledgerService->recordCustomerPayment($customer, ['amount' => '250.00', 'idempotency_key' => (string) Str::uuid()]);

        expect(Payment::query()->sole())
            ->ledger_entry_id->toBe($entry->id)
            ->amount->toBe('250.00');
        expect(SukiPoint::query()->sole())
            ->points_balance->toBe(2)
            ->repayment_remainder_cents->toBe(5000)
            ->completed_cycles->toBe(1);
        expect(DebtCycleCompletion::query()->count())->toBe(1);
        expect(LedgerEntry::query()->count())->toBe(1);
        expect($customer->fresh()->current_balance)->toBe('0.00');
    });
});

describe('atomicity, idempotency, and tenant isolation', function () {
    it('rolls back the credit, purchase, and balance when an item cannot be saved', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create();
        PurchaseItem::creating(function (): void {
            throw new RuntimeException('Simulated item failure.');
        });

        $this->actingAs($store->owner)
            ->from(route('credits.create'))
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => [['product_id' => (string) $product->id, 'quantity' => '1']],
            ]))
            ->assertRedirect(route('credits.create'))
            ->assertSessionHasErrors('credit');

        expectNothingRecorded($customer);
    });

    it('returns the original credit without duplicating the purchase when a submission is retried', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $products = Product::factory()->for($store)->count(2)->create();
        $payload = creditPayload($customer, [
            'items' => $products->map(fn (Product $product): array => ['product_id' => (string) $product->id, 'quantity' => '1.5'])->all(),
        ]);

        $this->actingAs($store->owner)->post(route('credits.store'), $payload);
        $entry = LedgerEntry::query()->sole();
        $this->post(route('credits.store'), $payload)->assertRedirect(route('credits.confirmation', $entry));

        expect(LedgerEntry::query()->count())->toBe(1);
        expect(Purchase::query()->count())->toBe(1);
        expect(PurchaseItem::query()->count())->toBe(2);
        expect($customer->fresh()->current_balance)->toBe('250.00');
    });

    it('rechecks products in the service, even when called without the form', function (Closure $makeItems, string $message) {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

        expect(fn () => app(LedgerService::class)->recordCredit($customer, [
            'amount' => '100.00',
            'items' => $makeItems($store),
        ]))->toThrow(InvalidPurchaseItems::class, $message);

        expectNothingRecorded($customer);
    })->with([
        'product deactivated after the form was validated' => [
            fn (Store $store) => [['product_id' => Product::factory()->for($store)->inactive()->create()->id, 'quantity' => '1']],
            'One or more selected products are not available in your active catalog. Remove them and try again.',
        ],
        'another store product' => [
            fn (Store $store) => [['product_id' => Product::factory()->create()->id, 'quantity' => '1']],
            'One or more selected products are not available in your active catalog. Remove them and try again.',
        ],
        'quantity with too many decimal places' => [
            fn (Store $store) => [['product_id' => Product::factory()->for($store)->create()->id, 'quantity' => '0.0005']],
            Quantity::FORMAT_MESSAGE,
        ],
        'duplicate product' => [
            function (Store $store): array {
                $productId = Product::factory()->for($store)->create()->id;

                return [['product_id' => $productId, 'quantity' => '1'], ['product_id' => $productId, 'quantity' => '1']];
            },
            'Each product can only be added once.',
        ],
    ]);

    it('rejects cross-store references in the database', function (Closure $insertInvalidRow) {
        $item = PurchaseItem::factory()->create();

        expect(fn () => $insertInvalidRow($item))->toThrow(QueryException::class);
    })->with([
        'item for another store product' => fn (PurchaseItem $item) => DB::table('purchase_items')->insert([
            'purchase_id' => $item->purchase_id,
            'store_id' => $item->store_id,
            'product_id' => Product::factory()->create()->id,
            'product_name_snapshot' => 'Foreign Product',
            'quantity' => '1.000',
        ]),
        'item whose store differs from its purchase' => fn (PurchaseItem $item) => DB::table('purchase_items')->insert([
            'purchase_id' => $item->purchase_id,
            'store_id' => ($otherProduct = Product::factory()->create())->store_id,
            'product_id' => $otherProduct->id,
            'product_name_snapshot' => 'Foreign Product',
            'quantity' => '1.000',
        ]),
        'purchase for another store customer' => fn (PurchaseItem $item) => DB::table('purchases')->insert([
            'store_id' => $item->store_id,
            'customer_id' => ($otherEntry = LedgerEntry::factory()->create())->customer_id,
            'ledger_entry_id' => $otherEntry->id,
            'purchase_type' => Purchase::TYPE_UTANG,
            'purchase_date' => now()->toDateString(),
        ]),
    ]);

    it('allows only one purchase per ledger entry', function () {
        $purchase = Purchase::factory()->create();

        expect(fn () => Purchase::factory()->create(['ledger_entry_id' => $purchase->ledger_entry_id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('product search', function () {
    it('returns only active products of the owner store that match the search', function () {
        $store = Store::factory()->create();
        Product::factory()->for($store)->create(['name' => 'Coke 1.5L', 'unit_price' => '65.00']);
        Product::factory()->for($store)->inactive()->create(['name' => 'Coke Zero']);
        Product::factory()->for($store)->create(['name' => 'Rice']);
        Product::factory()->create(['name' => 'Coke Other Store']);

        $this->actingAs($store->owner)
            ->getJson(route('credits.products', ['search' => 'coke']))
            ->assertOk()
            ->assertExactJson(['products' => [
                ['id' => Product::query()->where('name', 'Coke 1.5L')->value('id'), 'name' => 'Coke 1.5L', 'unit_price' => '65.00'],
            ]]);
    });

    it('redirects guests away from the product search', function () {
        $this->get(route('credits.products'))->assertRedirect(route('login'));
    });
});

describe('display', function () {
    it('shows structured items on the ledger and keeps legacy free text unchanged', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['name' => 'Rice 1kg', 'unit_price' => '60.00']);
        LedgerEntry::factory()->for($customer)->create(['itemized_list' => ['two 1.5 coke, piatos'], 'amount' => '100.00']);
        app(LedgerService::class)->recordCredit($customer, [
            'amount' => '50.00',
            'itemized_list' => ['candies'],
            'items' => [['product_id' => $product->id, 'quantity' => '0.25']],
        ]);

        $this->actingAs($store->owner)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('two 1.5 coke, piatos')
            ->assertSee('Rice 1kg × 0.25 @ ₱60.00 = ₱15.00, candies');
    });

    it('shows structured items on the overdue page', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['name' => 'Sardines', 'unit_price' => null]);
        app(LedgerService::class)->recordCredit($customer, [
            'amount' => '50.00',
            'transaction_date' => now()->subDays(10)->toDateString(),
            'repayment_deadline' => now()->subDays(2)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => '2']],
        ]);

        $this->actingAs($store->owner)->get(route('overdue-accounts.index'))
            ->assertOk()
            ->assertSee('Sardines × 2');
    });

    it('lists structured items before the free text in the credit receipt', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['name' => 'Rice <1kg>', 'unit_price' => '60.00']);
        $entry = app(LedgerService::class)->recordCredit($customer, [
            'amount' => '50.00',
            'itemized_list' => ['candies, gum'],
            'items' => [['product_id' => $product->id, 'quantity' => '0.25']],
        ]);

        $receipt = new CreditEntryReceipt($customer, $entry);

        expect($receipt->items)->toBe(['Rice <1kg> × 0.25 @ ₱60.00 = ₱15.00', 'candies', 'gum']);
        $receipt->assertSeeInHtml('Rice &lt;1kg&gt; × 0.25 @ ₱60.00 = ₱15.00', false);
    });

    it('shows the products and reference subtotal on the confirmation page', function (?string $riceUnitPrice, string $expectedSubtotalText) {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $rice = Product::factory()->for($store)->create(['name' => 'Rice 1kg', 'unit_price' => $riceUnitPrice]);
        $cannedGoods = Product::factory()->for($store)->create(['name' => 'Canned Goods', 'unit_price' => '35.00']);
        $entry = app(LedgerService::class)->recordCredit($customer, [
            'amount' => '250.00',
            'items' => [
                ['product_id' => $rice->id, 'quantity' => '0.25'],
                ['product_id' => $cannedGoods->id, 'quantity' => '2'],
            ],
        ]);

        $this->actingAs($store->owner)->get(route('credits.confirmation', $entry))
            ->assertOk()
            ->assertSee('Canned Goods × 2 @ ₱35.00 = ₱70.00')
            ->assertSee($expectedSubtotalText);
    })->with([
        'all prices known' => ['60.00', 'Item subtotal (reference only): ₱85.00. The credit of ₱250.00 is what the customer owes.'],
        'a price missing' => [null, 'Item subtotal unavailable because some products have no price.'],
    ]);

    it('keeps the selected products and entered quantity after a validation error', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $product = Product::factory()->for($store)->create(['name' => 'Rice 1kg']);

        $this->actingAs($store->owner)
            ->from(route('credits.create'))
            ->followingRedirects()
            ->post(route('credits.store'), creditPayload($customer, [
                'items' => [['product_id' => (string) $product->id, 'quantity' => '0.2555']],
            ]))
            ->assertOk()
            ->assertSee('Rice 1kg')
            ->assertSee('value="0.2555"', escape: false)
            ->assertSee(Quantity::FORMAT_MESSAGE);
    });
});
