<?php

use App\Models\User;

it('redirects guests away from the admin dashboard', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('forbids store owners from the admin dashboard', function () {
    $owner = User::factory()->withStore()->create();

    $this->actingAs($owner)->get(route('admin.dashboard'))->assertForbidden();
});

it('lets the super admin open the admin dashboard', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Super Admin Dashboard');
});

it('forbids the super admin from owner store screens', function (string $routeName) {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get(route($routeName))->assertForbidden();
})->with([
    'owner dashboard' => 'dashboard',
    'customer list' => 'customers.index',
    'record credit form' => 'credits.create',
]);

it('forbids an owner account without a store from store screens', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('dashboard'))->assertForbidden();
});

it('sends a signed-in user who opens the login page to their own dashboard', function (Closure $makeUser, string $expectedRoute) {
    $this->actingAs($makeUser())->get(route('login'))->assertRedirect(route($expectedRoute));
})->with([
    'store owner' => [fn () => User::factory()->withStore()->create(), 'dashboard'],
    'super admin' => [fn () => User::factory()->superAdmin()->create(), 'admin.dashboard'],
]);

it('ends an existing session once the account is deactivated', function () {
    $owner = User::factory()->withStore()->create();
    $this->actingAs($owner)->get(route('dashboard'))->assertOk();

    $owner->forceFill(['status' => User::STATUS_INACTIVE])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'username' => 'This account has been deactivated. Please contact the administrator.',
        ]);
    $this->assertGuest();
});

it('requires a temporary password to be changed before using the application', function () {
    $owner = User::factory()->withStore()->create([
        'password' => 'Temporary-pass-1',
        'must_change_password' => true,
    ]);

    $this->actingAs($owner)->get(route('customers.index'))->assertRedirect(route('password.edit'));
    $this->get(route('password.edit'))->assertOk();

    $this->put(route('password.update'), [
        'current_password' => 'Temporary-pass-1',
        'password' => 'Chosen-owner-pass2',
        'password_confirmation' => 'Chosen-owner-pass2',
    ])->assertRedirect(route('dashboard'));

    expect($owner->fresh()->must_change_password)->toBeFalse();
    $this->get(route('customers.index'))->assertOk();
});

it('does not let a user clear the temporary password flag through the password form', function () {
    $owner = User::factory()->withStore()->create([
        'password' => 'Temporary-pass-1',
        'must_change_password' => true,
    ]);

    $this->actingAs($owner)->put(route('password.update'), [
        'current_password' => 'incorrect-password',
        'password' => 'Chosen-owner-pass2',
        'password_confirmation' => 'Chosen-owner-pass2',
        'must_change_password' => '0',
        'role' => User::ROLE_SUPER_ADMIN,
    ])->assertSessionHasErrors('current_password');

    expect($owner->fresh())
        ->must_change_password->toBeTrue()
        ->role->toBe(User::ROLE_STORE_OWNER);
});
