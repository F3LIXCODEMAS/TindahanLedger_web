<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('shows the login form to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('TindahanLedger')
        ->assertSee('Sign In')
        ->assertSee('name="username"', false)
        ->assertSee('csrf-token', false);
});

it('logs in an owner with valid credentials and remembers the owner when requested', function () {
    $this->freezeSecond();
    $owner = User::factory()->withStore()->create([
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
    ]);

    $this->post('/login', [
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
        'remember' => '1',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh()->remember_token)->not->toBeNull();
    expect($owner->fresh()->last_login_at->equalTo(now()))->toBeTrue();

    $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
});

it('matches the username regardless of letter case and extra spaces', function () {
    $owner = User::factory()->withStore()->create([
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
    ]);

    $this->post('/login', [
        'username' => '  MANG   arthur store ',
        'password' => 'secret-password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
});

it('sends a super admin to the admin dashboard after login', function () {
    $superAdmin = User::factory()->superAdmin()->create([
        'username' => 'platform admin',
        'password' => 'secret-password',
    ]);

    $this->post('/login', [
        'username' => 'platform admin',
        'password' => 'secret-password',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($superAdmin);
});

it('sends a user with a temporary password to the change password page', function () {
    User::factory()->withStore()->create([
        'username' => 'Mang Arthur Store',
        'password' => 'temporary-password-1',
        'must_change_password' => true,
    ]);

    $this->post('/login', [
        'username' => 'Mang Arthur Store',
        'password' => 'temporary-password-1',
    ])->assertRedirect(route('password.edit'));
});

it('rejects a deactivated account even with the correct password', function () {
    User::factory()->withStore()->inactive()->create([
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
    ]);

    $this->from('/login')->post('/login', [
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
    ])->assertRedirect('/login')
        ->assertSessionHasErrors([
            'username' => 'This account has been deactivated. Please contact the administrator.',
        ]);

    $this->assertGuest();
});

it('rejects invalid credentials with a generic message', function () {
    User::factory()->withStore()->create([
        'username' => 'Mang Arthur Store',
        'password' => 'secret-password',
    ]);

    $response = $this->from('/login')->post('/login', [
        'username' => 'Mang Arthur Store',
        'password' => 'incorrect-password',
    ]);

    $response->assertRedirect('/login')
        ->assertSessionHasErrors([
            'username' => 'The username or password you entered is incorrect.',
        ]);
    $this->assertGuest();
});

it('validates required credentials and username length on the server', function () {
    $this->from('/login')->post('/login', [])
        ->assertRedirect('/login')
        ->assertInvalid([
            'username' => 'Please enter your username.',
            'password' => 'Please enter your password.',
        ]);

    $this->from('/login')->post('/login', [
        'username' => str_repeat('a', 121),
        'password' => 'secret-password',
    ])->assertRedirect('/login')->assertInvalid([
        'username' => 'The username may not exceed 120 characters.',
    ]);
});

it('redirects guests to login and logs authenticated owners out', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();

    $owner = User::factory()->withStore()->create();
    $this->actingAs($owner)->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('throttles repeated failed login attempts without revealing account details', function () {
    $username = 'throttle store';
    $throttleKey = $username.'|127.0.0.1';
    RateLimiter::clear($throttleKey);

    foreach (range(1, 5) as $attempt) {
        $this->from('/login')->post('/login', [
            'username' => $username,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors([
            'username' => 'The username or password you entered is incorrect.',
        ]);
    }

    $response = $this->from('/login')->post('/login', [
        'username' => $username,
        'password' => 'incorrect-password',
    ]);

    $response->assertRedirect('/login')->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))->toContain('Too many login attempts.');

    $this->assertGuest();
    RateLimiter::clear($throttleKey);
});

it('does not reveal whether an unknown username exists', function () {
    $response = $this->from('/login')->post('/login', [
        'username' => 'unknown store',
        'password' => 'incorrect-password',
    ]);

    $response->assertSessionHasErrors([
        'username' => 'The username or password you entered is incorrect.',
    ]);
    $this->assertGuest();
});
