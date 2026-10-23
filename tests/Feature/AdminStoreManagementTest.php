<?php

use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreManagementService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('forbids store owners from every store management action', function (string $method, Closure $url) {
    $owner = User::factory()->withStore()->create();

    $this->actingAs($owner)->call($method, $url($owner->store))->assertForbidden();
})->with([
    'store list' => ['GET', fn () => route('admin.stores.index')],
    'create store' => ['POST', fn () => route('admin.stores.store')],
    'store details' => ['GET', fn (Store $store) => route('admin.stores.show', $store)],
    'update store' => ['PUT', fn (Store $store) => route('admin.stores.update', $store)],
    'suspend store' => ['PATCH', fn (Store $store) => route('admin.stores.status.update', $store)],
    'reassign owner' => ['PATCH', fn (Store $store) => route('admin.stores.owner.update', $store)],
]);

describe('store list', function () {
    it('searches stores by store name or owner name', function () {
        Store::factory()->create(['store_name' => 'Mang Arthur Store']);
        Store::factory()->for(User::factory()->state(['name' => 'Nena Reyes']), 'owner')->create(['store_name' => 'Corner Shop']);
        Store::factory()->create(['store_name' => 'Unrelated Mart']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('admin.stores.index', ['search' => 'arthur']))
            ->assertOk()
            ->assertSee('Mang Arthur Store')
            ->assertDontSee('Corner Shop');

        $this->get(route('admin.stores.index', ['search' => 'Nena']))
            ->assertSee('Corner Shop')
            ->assertDontSee('Mang Arthur Store')
            ->assertDontSee('Unrelated Mart');
    });

    it('filters stores by status', function () {
        Store::factory()->create(['store_name' => 'Open Store']);
        Store::factory()->suspended()->create(['store_name' => 'Paused Store']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.index', ['status' => 'suspended']))
            ->assertSee('Paused Store')
            ->assertDontSee('Open Store');
    });

    it('sorts stores by outstanding debt and customer count', function () {
        $smallStore = Store::factory()->create(['store_name' => 'Small Debt Store']);
        $largeStore = Store::factory()->create(['store_name' => 'Large Debt Store']);
        LedgerEntry::factory()->for(Customer::factory()->for($smallStore))->create(['amount' => '100.00']);
        LedgerEntry::factory()->for(Customer::factory()->for($largeStore))->create(['amount' => '900.00']);
        Customer::factory()->count(2)->for($smallStore)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.stores.index', ['sort' => 'outstanding', 'direction' => 'desc']))
            ->assertSeeInOrder(['Large Debt Store', 'Small Debt Store']);

        $this->get(route('admin.stores.index', ['sort' => 'customers', 'direction' => 'desc']))
            ->assertSeeInOrder(['Small Debt Store', 'Large Debt Store']);
    });

    it('shows each store customer count with current outstanding and overdue balances', function () {
        $this->travelTo('2026-10-16 09:00:00');
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create();
        $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '800.00', 'repayment_deadline' => '2026-10-01']);
        Payment::factory()->for($customer)->for($entry)->create(['amount' => '250.00']);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.index'))
            ->assertOk();

        expect($response->viewData('stores')->first())
            ->customer_count->toBe(1)
            ->outstanding_balance->toBe('550.00')
            ->overdue_balance->toBe('550.00');
    });

    it('rejects sort and direction values outside the allowlist', function (array $query) {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.stores.index'))
            ->get(route('admin.stores.index', $query))
            ->assertSessionHasErrors(array_keys($query));
    })->with([
        'sql in sort' => [['sort' => 'store_name; drop table stores']],
        'unknown direction' => [['direction' => 'sideways']],
        'unknown status' => [['status' => 'deleted']],
    ]);
});

describe('creating a store', function () {
    it('creates the store and an owner with a temporary password', function () {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('admin.stores.store'), [
            'store_name' => '  Aling   Nena Store ',
            'name' => 'Nena Reyes',
            'username' => '',
            'email' => 'Nena@Example.Test',
            'temporary_password' => 'temporary-pass-123',
            'temporary_password_confirmation' => 'temporary-pass-123',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $store = Store::query()->with('owner')->sole();
        $response->assertRedirect(route('admin.stores.show', $store));
        expect($store->store_name)->toBe('Aling Nena Store');
        expect($store->isActive())->toBeTrue();
        expect($store->owner)
            ->username->toBe('aling nena store')
            ->email->toBe('nena@example.test')
            ->role->toBe(User::ROLE_STORE_OWNER)
            ->must_change_password->toBeTrue();
        expect(Hash::check('temporary-pass-123', $store->owner->password))->toBeTrue();
        expect(AdminAuditLog::query()->pluck('action')->sort()->values()->all())
            ->toBe([AdminAuditLog::ACTION_STORE_CREATED, AdminAuditLog::ACTION_USER_CREATED]);
    });

    it('rejects a taken username or email without creating anything', function () {
        User::factory()->create(['username' => 'aling nena store', 'email' => 'nena@example.test']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->from(route('admin.stores.create'))->post(route('admin.stores.store'), [
            'store_name' => 'Aling Nena Store',
            'name' => 'Nena Reyes',
            'email' => 'NENA@example.test',
            'temporary_password' => 'temporary-pass-123',
            'temporary_password_confirmation' => 'temporary-pass-123',
        ])->assertRedirect(route('admin.stores.create'))
            ->assertInvalid([
                'username' => 'This username is already taken.',
                'email' => 'This email address is already used by another account.',
            ]);

        expect(Store::query()->count())->toBe(0);
        expect(AdminAuditLog::query()->count())->toBe(0);
    });

    it('requires the store and owner fields and a strong confirmed temporary password', function () {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.stores.create'))
            ->post(route('admin.stores.store'), [
                'temporary_password' => 'short',
                'temporary_password_confirmation' => 'different',
            ])
            ->assertInvalid(['store_name', 'name', 'email', 'temporary_password']);
    });

    it('creates a store for an existing owner without changing their account', function () {
        $owner = User::factory()->create(['name' => 'Eman Ariza', 'username' => 'arizastore19']);
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('admin.stores.store'), [
            'store_name' => ' Ariza   Store ',
            'owner_type' => 'existing',
            'owner_user_id' => $owner->id,
            'name' => 'Ignored Owner',
            'email' => 'ignored@example.test',
            'temporary_password' => 'temporary-pass-123',
            'temporary_password_confirmation' => 'temporary-pass-123',
        ]);

        $store = Store::query()->sole();
        $response->assertRedirect(route('admin.stores.show', $store))
            ->assertSessionHas('status', 'Store created and assigned to Eman Ariza. They sign in with their existing username and password.');
        expect($store->store_name)->toBe('Ariza Store');
        expect($store->owner_user_id)->toBe($owner->id);
        expect($store->isActive())->toBeTrue();
        expect(User::query()->count())->toBe(2);
        expect($owner->fresh())
            ->username->toBe('arizastore19')
            ->must_change_password->toBeFalse();
        expect(Hash::check('password', $owner->fresh()->password))->toBeTrue();
        expect(AdminAuditLog::query()->sole())
            ->action->toBe(AdminAuditLog::ACTION_STORE_CREATED)
            ->details->toBe(['store_name' => 'Ariza Store', 'owner_user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    });

    it('rejects an existing owner who cannot receive a store', function (Closure $makeCandidate) {
        $candidate = $makeCandidate();
        $storeCount = Store::query()->count();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.stores.create'))
            ->post(route('admin.stores.store'), [
                'store_name' => 'Ariza Store',
                'owner_type' => 'existing',
                'owner_user_id' => $candidate->id,
            ])
            ->assertRedirect(route('admin.stores.create'))
            ->assertInvalid(['owner_user_id' => 'Choose an active store owner account that is not assigned to another store.']);

        expect(Store::query()->count())->toBe($storeCount);
        expect(AdminAuditLog::query()->count())->toBe(0);
    })->with([
        'owner of another store' => fn () => User::factory()->withStore()->create(),
        'deactivated owner' => fn () => User::factory()->inactive()->create(),
        'super admin' => fn () => User::factory()->superAdmin()->create(),
    ]);

    it('requires an owner account when using an existing owner', function () {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.stores.store'), ['store_name' => 'Ariza Store', 'owner_type' => 'existing'])
            ->assertInvalid(['owner_user_id' => 'Choose the owner account for this store.'])
            ->assertValid(['name', 'email', 'temporary_password']);

        expect(Store::query()->count())->toBe(0);
    });

    it('rejects an unknown owner type', function () {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.stores.store'), ['store_name' => 'Ariza Store', 'owner_type' => 'borrowed'])
            ->assertInvalid(['owner_type' => 'Choose whether to create a new owner or use an existing one.']);

        expect(Store::query()->count())->toBe(0);
    });

    it('refuses an owner who received a store after the form was validated', function () {
        $superAdmin = User::factory()->superAdmin()->create();
        $owner = User::factory()->withStore()->create();

        expect(fn () => app(StoreManagementService::class)->createStoreForExistingOwner($superAdmin, 'Second Store', $owner))
            ->toThrow(ValidationException::class);

        expect(Store::query()->count())->toBe(1);
        expect(AdminAuditLog::query()->count())->toBe(0);
    });

    it('lists only owners without a store and preselects the requested owner', function () {
        $waitingOwner = User::factory()->create(['name' => 'Waiting Owner']);
        User::factory()->withStore()->create(['name' => 'Assigned Owner']);
        User::factory()->inactive()->create(['name' => 'Deactivated Owner']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.create', ['owner_user_id' => $waitingOwner->id]))
            ->assertOk()
            ->assertSee('Waiting Owner')
            ->assertDontSee('Assigned Owner')
            ->assertDontSee('Deactivated Owner')
            ->assertSee('value="'.$waitingOwner->id.'" selected', escape: false)
            ->assertSee('aria-controls="temporary_password"', escape: false);
    });
});

it('updates the store name and records the change', function () {
    $store = Store::factory()->create(['store_name' => 'Old Name']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.stores.update', $store), ['store_name' => 'New Name'])
        ->assertRedirect(route('admin.stores.show', $store));

    expect($store->fresh()->store_name)->toBe('New Name');
    expect(AdminAuditLog::query()->sole())
        ->action->toBe(AdminAuditLog::ACTION_STORE_UPDATED)
        ->details->toBe(['previous_store_name' => 'Old Name', 'store_name' => 'New Name']);
});

it('suspends and reactivates a store while keeping its records', function () {
    $store = Store::factory()->create();
    $customer = Customer::factory()->for($store)->create();
    $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '500.00']);
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch(route('admin.stores.status.update', $store), ['status' => 'suspended'])
        ->assertRedirect(route('admin.stores.show', $store));
    expect($store->fresh()->status)->toBe(Store::STATUS_SUSPENDED);

    $this->patch(route('admin.stores.status.update', $store), ['status' => 'active']);
    expect($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);

    $this->assertModelExists($customer);
    expect($entry->fresh()->amount)->toBe('500.00');
    expect(AdminAuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe([AdminAuditLog::ACTION_STORE_SUSPENDED, AdminAuditLog::ACTION_STORE_REACTIVATED]);
});

it('does not record a status change that changes nothing', function () {
    $store = Store::factory()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->patch(route('admin.stores.status.update', $store), ['status' => 'active'])
        ->assertSessionHas('status', 'The store status was already up to date.');

    expect(AdminAuditLog::query()->count())->toBe(0);
});

describe('reassigning a store owner', function () {
    it('moves the store to the new owner and keeps its history with the store', function () {
        $store = Store::factory()->create();
        $previousOwner = $store->owner;
        $customer = Customer::factory()->for($store)->create();
        $entry = LedgerEntry::factory()->for($customer)->create();
        $newOwner = User::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->patch(route('admin.stores.owner.update', $store), ['owner_user_id' => $newOwner->id])
            ->assertRedirect(route('admin.stores.show', $store));

        expect($store->fresh()->owner_user_id)->toBe($newOwner->id);
        expect($customer->fresh()->store_id)->toBe($store->id);
        expect($entry->fresh()->customer_id)->toBe($customer->id);
        expect($previousOwner->fresh()->store)->toBeNull();
        expect(AdminAuditLog::query()->sole()->details)->toBe([
            'previous_owner_user_id' => $previousOwner->id,
            'owner_user_id' => $newOwner->id,
        ]);
    });

    it('rejects an owner who is unavailable for the store', function (Closure $makeCandidate) {
        $store = Store::factory()->create();
        $originalOwnerId = $store->owner_user_id;

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.stores.show', $store))
            ->patch(route('admin.stores.owner.update', $store), ['owner_user_id' => $makeCandidate()->id])
            ->assertInvalid(['owner_user_id']);

        expect($store->fresh()->owner_user_id)->toBe($originalOwnerId);
    })->with([
        'owner of another store' => fn () => User::factory()->withStore()->create(),
        'deactivated owner' => fn () => User::factory()->inactive()->create(),
        'super admin' => fn () => User::factory()->superAdmin()->create(),
    ]);
});

describe('store details', function () {
    it('shows the store customers, balances, and recent transactions', function () {
        $store = Store::factory()->create(['store_name' => 'Detail Store']);
        $customer = Customer::factory()->for($store)->create(['full_name' => 'Ledger Customer']);
        $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '700.00']);
        Payment::factory()->for($customer)->for($entry)->create(['amount' => '200.00', 'payment_batch_id' => 'batch-1']);
        Customer::factory()->create(['full_name' => 'Other Store Customer']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.show', $store))
            ->assertOk()
            ->assertSee('Detail Store')
            ->assertSee('Ledger Customer')
            ->assertSee('₱500.00')
            ->assertSee('₱700.00')
            ->assertSee('₱200.00')
            ->assertDontSee('Other Store Customer');
    });

    it('opens a store customer ledger in read-only form', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['full_name' => 'Ledger Customer']);
        LedgerEntry::factory()->for($customer)->create(['amount' => '700.00']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.customers.show', [$store, $customer]))
            ->assertOk()
            ->assertSee('Ledger Customer')
            ->assertSee('READ-ONLY LEDGER')
            ->assertDontSee('Record Credit')
            ->assertDontSee('Edit details');
    });

    it('responds as not found for a customer that belongs to another store', function () {
        $store = Store::factory()->create();
        $otherCustomer = Customer::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.customers.show', [$store, $otherCustomer]))
            ->assertNotFound();
    });
});
