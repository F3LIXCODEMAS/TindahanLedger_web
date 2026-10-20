<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an active super admin with a hashed password', function () {
    $this->artisan('admin:create', ['username' => ' Platform  Admin ', 'email' => 'Admin@Example.Test'])
        ->expectsQuestion('Password (at least 12 characters, with letters and numbers)', 'admin-password-123')
        ->expectsQuestion('Confirm password', 'admin-password-123')
        ->assertSuccessful();

    $superAdmin = User::query()->sole();
    expect($superAdmin->username)->toBe('platform admin');
    expect($superAdmin->email)->toBe('admin@example.test');
    expect($superAdmin->isSuperAdmin())->toBeTrue();
    expect($superAdmin->isActive())->toBeTrue();
    expect(Hash::check('admin-password-123', $superAdmin->password))->toBeTrue();
});

it('rejects a username that is already taken without asking for a password', function () {
    User::factory()->create(['username' => 'platform admin']);

    $this->artisan('admin:create', ['username' => 'Platform Admin', 'email' => 'admin@example.test'])
        ->assertFailed();

    expect(User::query()->where('role', User::ROLE_SUPER_ADMIN)->exists())->toBeFalse();
});

it('rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'admin@example.test']);

    $this->artisan('admin:create', ['username' => 'platform admin', 'email' => 'admin@example.test'])
        ->assertFailed();

    expect(User::query()->where('role', User::ROLE_SUPER_ADMIN)->exists())->toBeFalse();
});

it('rejects a password that is weak or not confirmed', function (string $password, string $confirmation) {
    $this->artisan('admin:create', ['username' => 'platform admin', 'email' => 'admin@example.test'])
        ->expectsQuestion('Password (at least 12 characters, with letters and numbers)', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['short-pw-1', 'short-pw-1'],
    'no numbers' => ['only-letters-here', 'only-letters-here'],
    'confirmation mismatch' => ['admin-password-123', 'admin-password-456'],
]);
