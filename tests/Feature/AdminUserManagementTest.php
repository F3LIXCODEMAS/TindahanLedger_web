<?php

use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('forbids store owners from every user management action', function (string $method, Closure $url) {
    $owner = User::factory()->withStore()->create();

    $this->actingAs($owner)->call($method, $url($owner))->assertForbidden();
})->with([
    'user list' => ['GET', fn () => route('admin.users.index')],
    'create owner' => ['POST', fn () => route('admin.users.store')],
    'user details' => ['GET', fn (User $user) => route('admin.users.show', $user)],
    'change status' => ['PATCH', fn (User $user) => route('admin.users.status.update', $user)],
    'issue temporary password' => ['PUT', fn (User $user) => route('admin.users.password.update', $user)],
]);

it('filters accounts by search, store, role, and status', function (array $query, string $expectedName) {
    $store = Store::factory()->for(User::factory()->state(['name' => 'Assigned Owner']), 'owner')->create();
    User::factory()->inactive()->create(['name' => 'Inactive Owner', 'email' => 'inactive@example.test']);
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Platform Admin']);
    $query = array_map(fn (mixed $value) => $value === ':store' ? $store->id : $value, $query);

    $response = $this->actingAs($superAdmin)->get(route('admin.users.index', $query))->assertOk();

    expect($response->viewData('users')->pluck('name')->all())->toBe([$expectedName]);
})->with([
    'search by email' => [['search' => 'inactive@'], 'Inactive Owner'],
    'assigned store' => [['store_id' => ':store'], 'Assigned Owner'],
    'role' => [['role' => 'super_admin'], 'Platform Admin'],
    'status' => [['status' => 'inactive'], 'Inactive Owner'],
]);

it('creates an unassigned store owner with a temporary password and never an admin', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->post(route('admin.users.store'), [
        'name' => 'Replacement Owner',
        'username' => 'Replacement Owner',
        'email' => 'replacement@example.test',
        'temporary_password' => 'temporary-pass-123',
        'temporary_password_confirmation' => 'temporary-pass-123',
        'role' => User::ROLE_SUPER_ADMIN,
        'status' => User::STATUS_INACTIVE,
    ])->assertRedirect();

    $owner = User::query()->where('email', 'replacement@example.test')->sole();
    expect($owner)
        ->username->toBe('replacement owner')
        ->role->toBe(User::ROLE_STORE_OWNER)
        ->status->toBe(User::STATUS_ACTIVE)
        ->must_change_password->toBeTrue()
        ->store->toBeNull();
    expect(AdminAuditLog::query()->sole()->action)->toBe(AdminAuditLog::ACTION_USER_CREATED);
});

it('deactivates an owner without touching store records and reactivates them', function () {
    $owner = User::factory()->withStore()->create();
    $customer = Customer::factory()->for($owner->store)->create();
    $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '450.00']);
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch(route('admin.users.status.update', $owner), ['status' => 'inactive'])
        ->assertRedirect(route('admin.users.show', $owner));

    expect($owner->fresh()->isActive())->toBeFalse();
    expect($owner->fresh()->store->id)->toBe($customer->store_id);
    expect($entry->fresh()->amount)->toBe('450.00');

    $this->patch(route('admin.users.status.update', $owner), ['status' => 'active']);

    expect($owner->fresh()->isActive())->toBeTrue();
    expect(AdminAuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe([AdminAuditLog::ACTION_USER_DEACTIVATED, AdminAuditLog::ACTION_USER_REACTIVATED]);
});

it('refuses to change the status of an admin account or the acting admin', function (Closure $makeTarget) {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = $makeTarget($superAdmin);

    $this->actingAs($superAdmin)
        ->from(route('admin.users.show', $target))
        ->patch(route('admin.users.status.update', $target), ['status' => 'inactive'])
        ->assertSessionHasErrors(['account' => 'Only store owner accounts can be managed here.']);

    expect($target->fresh()->isActive())->toBeTrue();
    expect(AdminAuditLog::query()->count())->toBe(0);
})->with([
    'another super admin' => fn () => User::factory()->superAdmin()->create(),
    'the acting super admin' => fn (User $superAdmin) => $superAdmin,
]);

it('issues a temporary password that the owner must replace', function () {
    $owner = User::factory()->withStore()->create(['password' => 'Original-pass-123']);
    $originalRememberToken = $owner->remember_token;

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.users.password.update', $owner), [
            'temporary_password' => 'temporary-pass-456',
            'temporary_password_confirmation' => 'temporary-pass-456',
        ])->assertRedirect(route('admin.users.show', $owner));

    $owner->refresh();
    expect(Hash::check('temporary-pass-456', $owner->password))->toBeTrue();
    expect($owner->must_change_password)->toBeTrue();
    expect($owner->remember_token)->not->toBe($originalRememberToken);

    $auditLog = AdminAuditLog::query()->sole();
    expect($auditLog->action)->toBe(AdminAuditLog::ACTION_USER_TEMPORARY_PASSWORD_SET);
    expect(json_encode($auditLog->details))->not->toContain('temporary-pass-456');
});

it('links an active owner without a store to store creation', function () {
    $waitingOwner = User::factory()->create();
    $assignedOwner = User::factory()->withStore()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $createStoreUrl = fn (User $owner): string => route('admin.stores.create', ['owner_user_id' => $owner->id]);

    $this->actingAs($superAdmin)->get(route('admin.users.show', $waitingOwner))
        ->assertSee($createStoreUrl($waitingOwner), escape: false);

    $this->get(route('admin.users.show', $assignedOwner))
        ->assertDontSee('Create a store for this owner');
});

it('shows account details without the password', function () {
    $owner = User::factory()->withStore()->create(['username' => 'mang arthur store']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.users.show', $owner))
        ->assertOk()
        ->assertSee('mang arthur store')
        ->assertSee($owner->store->store_name)
        ->assertDontSee($owner->password);
});
