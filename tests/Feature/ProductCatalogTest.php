<?php

use App\Http\Requests\SaveProductRequest;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\SukiPoint;
use App\Models\SukiPointTransaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('access', function () {
    it('redirects guests to the login page', function (string $method, Closure $url) {
        $product = Product::factory()->create();

        $this->call($method, $url($product), ['name' => 'Rice', 'status' => 'inactive'])
            ->assertRedirect(route('login'));

        expect($product->fresh()->status)->toBe(Product::STATUS_ACTIVE);
        expect(Product::query()->count())->toBe(1);
    })->with([
        'catalog' => ['GET', fn () => route('products.index')],
        'add form' => ['GET', fn () => route('products.create')],
        'add product' => ['POST', fn () => route('products.store')],
        'edit form' => ['GET', fn (Product $product) => route('products.edit', $product)],
        'update product' => ['PUT', fn (Product $product) => route('products.update', $product)],
        'change status' => ['PATCH', fn (Product $product) => route('products.status.update', $product)],
    ]);

    it('forbids the super admin from the product catalog', function () {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('products.index'))
            ->assertForbidden();
    });

    it('responds as not found when an owner targets another store product', function (string $method, Closure $url) {
        $owner = User::factory()->withStore()->create();
        $otherProduct = Product::factory()->create(['name' => 'Coke', 'unit_price' => '20.00']);

        $this->actingAs($owner)
            ->call($method, $url($otherProduct), ['name' => 'Hijacked', 'status' => Product::STATUS_INACTIVE])
            ->assertNotFound();

        $otherProduct->refresh();
        expect($otherProduct->name)->toBe('Coke');
        expect($otherProduct->unit_price)->toBe('20.00');
        expect($otherProduct->status)->toBe(Product::STATUS_ACTIVE);
    })->with([
        'edit form' => ['GET', fn (Product $product) => route('products.edit', $product)],
        'update product' => ['PUT', fn (Product $product) => route('products.update', $product)],
        'change status' => ['PATCH', fn (Product $product) => route('products.status.update', $product)],
    ]);

    it('blocks catalog changes for a suspended store without writing records', function (string $method, Closure $url) {
        $store = Store::factory()->suspended()->create();
        $product = Product::factory()->for($store)->create(['name' => 'Rice']);

        $this->actingAs($store->owner)
            ->call($method, $url($product), ['name' => 'Changed', 'status' => Product::STATUS_INACTIVE])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('store');

        expect(Product::query()->count())->toBe(1);
        expect($product->fresh()->name)->toBe('Rice');
        expect($product->fresh()->status)->toBe(Product::STATUS_ACTIVE);
    })->with([
        'add form' => ['GET', fn () => route('products.create')],
        'add product' => ['POST', fn () => route('products.store')],
        'edit form' => ['GET', fn (Product $product) => route('products.edit', $product)],
        'update product' => ['PUT', fn (Product $product) => route('products.update', $product)],
        'change status' => ['PATCH', fn (Product $product) => route('products.status.update', $product)],
    ]);

    it('keeps the catalog readable for a suspended store', function () {
        $store = Store::factory()->suspended()->create();
        Product::factory()->for($store)->create(['name' => 'Rice']);

        $this->actingAs($store->owner)->get(route('products.index'))->assertOk()->assertSee('Rice');
    });
});

describe('index', function () {
    it('lists only the products of the owner store', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Sardines']);
        Product::factory()->create(['name' => 'Other Store Soap']);

        $this->actingAs($owner)->get(route('products.index'))
            ->assertOk()
            ->assertSee('Sardines')
            ->assertDontSee('Other Store Soap');
    });

    it('shows an empty state when the store has no products', function () {
        $this->actingAs(User::factory()->withStore()->create())
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('No products have been added yet.');
    });

    it('searches product names without regard to letter case', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Coke 1.5L']);
        Product::factory()->for($owner->store)->create(['name' => 'Rice']);

        $this->actingAs($owner)->get(route('products.index', ['search' => '  COKE ']))
            ->assertOk()
            ->assertSee('Coke 1.5L')
            ->assertDontSee('Rice');
    });

    it('treats percent and underscore in a search as literal characters', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => '100% Juice']);
        Product::factory()->for($owner->store)->create(['name' => 'Rice']);

        $this->actingAs($owner)->get(route('products.index', ['search' => '%']))
            ->assertSee('100% Juice')
            ->assertDontSee('Rice');

        $this->get(route('products.index', ['search' => '_']))
            ->assertSee('No products match these filters.');
    });

    it('filters products by status', function (string $status, string $visible, string $hidden) {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Active Rice']);
        Product::factory()->for($owner->store)->inactive()->create(['name' => 'Retired Soap']);

        $this->actingAs($owner)->get(route('products.index', ['status' => $status]))
            ->assertOk()
            ->assertSee($visible)
            ->assertDontSee($hidden);
    })->with([
        'active only' => [Product::STATUS_ACTIVE, 'Active Rice', 'Retired Soap'],
        'inactive only' => [Product::STATUS_INACTIVE, 'Retired Soap', 'Active Rice'],
    ]);

    it('rejects an unknown status filter', function () {
        $this->actingAs(User::factory()->withStore()->create())
            ->get(route('products.index', ['status' => 'deleted']))
            ->assertSessionHasErrors('status');
    });

    it('paginates the catalog twenty products at a time', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->count(21)
            ->sequence(fn ($sequence) => ['name' => sprintf('Item %02d', $sequence->index + 1)])
            ->create();

        $this->actingAs($owner)->get(route('products.index'))
            ->assertSee('Item 20')
            ->assertDontSee('Item 21');

        $this->get(route('products.index', ['page' => 2]))
            ->assertSee('Item 21')
            ->assertDontSee('Item 20');
    });
});

describe('store', function () {
    it('creates a product for the owner store with a trimmed name and normalized copy', function () {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)->post(route('products.store'), [
            'name' => '  Coke   1.5L  ',
            'unit_price' => ' 65.50 ',
            'description' => '  Family size  ',
        ])->assertRedirect(route('products.index'))
            ->assertSessionHas('status', 'Product added successfully.');

        $product = Product::query()->sole();
        expect($product->store_id)->toBe($owner->store->id);
        expect($product->name)->toBe('Coke 1.5L');
        expect($product->normalized_name)->toBe('coke 1.5l');
        expect($product->unit_price)->toBe('65.50');
        expect($product->description)->toBe('Family size');
        expect($product->status)->toBe(Product::STATUS_ACTIVE);
    });

    it('saves a product without the optional price and description', function () {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)
            ->post(route('products.store'), ['name' => 'Rice', 'unit_price' => '', 'description' => '   '])
            ->assertRedirect(route('products.index'));

        $product = Product::query()->sole();
        expect($product->unit_price)->toBeNull();
        expect($product->description)->toBeNull();
    });

    it('accepts a zero unit price', function () {
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('products.store'), ['name' => 'Free Sample', 'unit_price' => '0'])
            ->assertRedirect(route('products.index'));

        expect(Product::query()->sole()->unit_price)->toBe('0.00');
    });

    it('ignores submitted store, status, and normalized name fields', function () {
        $owner = User::factory()->withStore()->create();
        $otherStore = Store::factory()->create();

        $this->actingAs($owner)->post(route('products.store'), [
            'name' => 'Rice',
            'store_id' => $otherStore->id,
            'status' => Product::STATUS_INACTIVE,
            'normalized_name' => 'something else',
        ])->assertRedirect(route('products.index'));

        $product = Product::query()->sole();
        expect($product->store_id)->toBe($owner->store->id);
        expect($product->status)->toBe(Product::STATUS_ACTIVE);
        expect($product->normalized_name)->toBe('rice');
    });

    it('rejects a missing or blank product name', function (array $payload) {
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('products.store'), $payload)
            ->assertSessionHasErrors(['name' => 'Please enter the product name.']);

        expect(Product::query()->count())->toBe(0);
    })->with([
        'missing' => [[]],
        'empty' => [['name' => '']],
        'spaces only' => [['name' => "   \t "]],
    ]);

    it('rejects a product name longer than 120 characters', function () {
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('products.store'), ['name' => str_repeat('a', 121)])
            ->assertSessionHasErrors(['name' => 'The product name may not exceed 120 characters.']);

        expect(Product::query()->count())->toBe(0);
    });

    it('rejects an invalid unit price', function (string $unitPrice, string $message) {
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('products.store'), ['name' => 'Rice', 'unit_price' => $unitPrice])
            ->assertSessionHasErrors(['unit_price' => $message]);

        expect(Product::query()->count())->toBe(0);
    })->with([
        'negative' => ['-1.00', 'The unit price cannot be negative.'],
        'three decimal places' => ['10.555', 'Enter a price of zero or more with no more than two decimal places.'],
        'not a number' => ['ten pesos', 'The unit price must be a valid amount.'],
        'too large' => ['10000000000.00', 'Enter a price of zero or more with no more than two decimal places.'],
    ]);

    it('rejects a description longer than 500 characters', function () {
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('products.store'), ['name' => 'Rice', 'description' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['description' => 'The description may not exceed 500 characters.']);
    });

    it('rejects a name that duplicates a product in the same store', function (string $submittedName) {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Coke']);

        $this->actingAs($owner)
            ->post(route('products.store'), ['name' => $submittedName])
            ->assertSessionHasErrors(['name' => SaveProductRequest::DUPLICATE_NAME_MESSAGE]);

        expect(Product::query()->count())->toBe(1);
    })->with([
        'same name' => ['Coke'],
        'different letter case' => ['cOKE'],
        'surrounding spaces' => ['  Coke  '],
    ]);

    it('tells the owner to reactivate an inactive product with the same name', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->inactive()->create(['name' => 'Rice']);

        $this->actingAs($owner)
            ->post(route('products.store'), ['name' => 'rice'])
            ->assertSessionHasErrors(['name' => SaveProductRequest::DUPLICATE_INACTIVE_NAME_MESSAGE]);

        expect(Product::query()->count())->toBe(1);
    });

    it('allows two stores to use the same product name', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->create(['name' => 'Coke']);

        $this->actingAs($owner)
            ->post(route('products.store'), ['name' => 'Coke'])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        expect(Product::query()->where('normalized_name', 'coke')->count())->toBe(2);
    });

    it('shows a duplicate error when a concurrent request saves the same name first', function () {
        $owner = User::factory()->withStore()->create();

        Product::creating(function (Product $product): void {
            DB::table('products')->insert([
                'store_id' => $product->store_id,
                'name' => 'Rice',
                'normalized_name' => 'rice',
                'status' => Product::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($owner)
            ->from(route('products.create'))
            ->post(route('products.store'), ['name' => 'Rice'])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['name' => SaveProductRequest::DUPLICATE_NAME_MESSAGE]);

        expect(Product::query()->count())->toBe(1);
    });
});

describe('update', function () {
    it('renames and reprices a product while keeping its identity', function () {
        $owner = User::factory()->withStore()->create();
        $product = Product::factory()->for($owner->store)->create(['name' => 'Coke', 'unit_price' => '20.00']);

        $this->actingAs($owner)->put(route('products.update', $product), [
            'name' => ' Coca-Cola  Regular ',
            'unit_price' => '22.50',
        ])->assertRedirect(route('products.index'))
            ->assertSessionHas('status', 'Product updated successfully.');

        $updatedProduct = Product::query()->sole();
        expect($updatedProduct->id)->toBe($product->id);
        expect($updatedProduct->name)->toBe('Coca-Cola Regular');
        expect($updatedProduct->normalized_name)->toBe('coca-cola regular');
        expect($updatedProduct->unit_price)->toBe('22.50');
        expect($updatedProduct->store_id)->toBe($owner->store->id);
    });

    it('lets a product keep its own name with different letter case', function () {
        $owner = User::factory()->withStore()->create();
        $product = Product::factory()->for($owner->store)->create(['name' => 'coke']);

        $this->actingAs($owner)
            ->put(route('products.update', $product), ['name' => 'Coke'])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        expect($product->fresh()->name)->toBe('Coke');
    });

    it('rejects a rename that conflicts with another product in the store', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Rice']);
        $product = Product::factory()->for($owner->store)->create(['name' => 'Sardines']);

        $this->actingAs($owner)
            ->put(route('products.update', $product), ['name' => 'RICE'])
            ->assertSessionHasErrors(['name' => SaveProductRequest::DUPLICATE_NAME_MESSAGE]);

        expect($product->fresh()->name)->toBe('Sardines');
    });

    it('opens the edit form with the current product details', function () {
        $owner = User::factory()->withStore()->create();
        $product = Product::factory()->for($owner->store)->create(['name' => 'Rice', 'unit_price' => '55.00']);

        $this->actingAs($owner)->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Edit product')
            ->assertSee('value="Rice"', escape: false)
            ->assertSee('value="55.00"', escape: false);
    });
});

describe('status', function () {
    it('deactivates and reactivates a product without deleting it', function () {
        $owner = User::factory()->withStore()->create();
        $product = Product::factory()->for($owner->store)->create(['name' => 'Rice']);

        $this->actingAs($owner)
            ->patch(route('products.status.update', $product), ['status' => Product::STATUS_INACTIVE])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status', 'Rice deactivated. It stays in your records but cannot be chosen for new purchases.');

        expect(Product::query()->sole()->status)->toBe(Product::STATUS_INACTIVE);

        $this->patch(route('products.status.update', $product), ['status' => Product::STATUS_ACTIVE])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status', 'Rice reactivated.');

        $reactivatedProduct = Product::query()->sole();
        expect($reactivatedProduct->id)->toBe($product->id);
        expect($reactivatedProduct->status)->toBe(Product::STATUS_ACTIVE);
    });

    it('rejects an unknown status value', function () {
        $owner = User::factory()->withStore()->create();
        $product = Product::factory()->for($owner->store)->create();

        $this->actingAs($owner)
            ->patch(route('products.status.update', $product), ['status' => 'deleted'])
            ->assertSessionHasErrors('status');

        expect($product->fresh()->status)->toBe(Product::STATUS_ACTIVE);
    });

    it('asks for confirmation before deactivating from the catalog', function () {
        $owner = User::factory()->withStore()->create();
        Product::factory()->for($owner->store)->create(['name' => 'Rice']);
        Product::factory()->for($owner->store)->inactive()->create(['name' => 'Soap']);

        $this->actingAs($owner)->get(route('products.index'))
            ->assertSee('data-confirm="Deactivate Rice?', escape: false)
            ->assertSee('Reactivate')
            ->assertDontSee('data-confirm="Deactivate Soap?', escape: false);
    });
});

describe('database integrity', function () {
    it('enforces unique normalized names per store in the database', function () {
        $product = Product::factory()->create(['name' => 'Rice']);

        expect(fn () => Product::factory()->for($product->store)->create(['name' => ' RICE ']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('prevents deleting a store that still has products', function () {
        $product = Product::factory()->create();

        expect(fn () => DB::table('stores')->where('id', $product->store_id)->delete())
            ->toThrow(QueryException::class);

        expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
    });

    it('leaves balances, ledger entries, payments, and Suki Points unchanged', function () {
        $owner = User::factory()->withStore()->create();
        $customer = Customer::factory()->for($owner->store)->create(['credit_limit' => '5000.00']);
        $ledgerService = app(LedgerService::class);
        $ledgerService->recordCredit($customer, ['amount' => '300.00']);
        $ledgerService->recordCustomerPayment($customer, ['amount' => '100.00', 'idempotency_key' => (string) Str::uuid()]);
        $snapshot = fn (): array => [
            'balance' => $customer->fresh()->current_balance,
            'ledger_entries' => LedgerEntry::query()->orderBy('id')->get(['id', 'amount', 'running_balance'])->toArray(),
            'payments' => Payment::query()->orderBy('id')->get(['id', 'amount', 'kind'])->toArray(),
            'suki_points' => SukiPoint::query()->get(['customer_id', 'points_balance', 'repayment_remainder_cents'])->toArray(),
            'suki_point_transactions' => SukiPointTransaction::query()->count(),
        ];
        $before = $snapshot();

        $this->actingAs($owner)->post(route('products.store'), ['name' => 'Rice', 'unit_price' => '50.00']);
        $product = Product::query()->sole();
        $this->put(route('products.update', $product), ['name' => 'Rice 1kg', 'unit_price' => '55.00']);
        $this->patch(route('products.status.update', $product), ['status' => Product::STATUS_INACTIVE]);

        expect($snapshot())->toBe($before);
    });
});
