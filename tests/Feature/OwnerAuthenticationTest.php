<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('shows the owner login form to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('TindahanLedger')
        ->assertSee('Owner Sign In')
        ->assertSee('csrf-token', false);
});

it('logs in an owner with valid credentials and remembers the owner when requested', function () {
    $owner = User::factory()->create([
        'email' => 'owner@example.test',
        'password' => 'secret-password',
    ]);

    $this->post('/login', [
        'email' => 'owner@example.test',
        'password' => 'secret-password',
        'remember' => '1',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh()->remember_token)->not->toBeNull();

    $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
});

it('rejects invalid credentials with a generic message', function () {
    User::factory()->create([
        'email' => 'owner@example.test',
        'password' => 'secret-password',
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'owner@example.test',
        'password' => 'incorrect-password',
    ]);

    $response->assertRedirect('/login')
        ->assertSessionHasErrors([
            'email' => 'The email or password you entered is incorrect.',
        ]);
    $this->assertGuest();
});

it('validates required credentials and email format on the server', function () {
    $this->from('/login')->post('/login', [])
        ->assertRedirect('/login')
        ->assertInvalid([
            'email' => 'Please enter your email.',
            'password' => 'Please enter your password.',
        ]);

    $this->from('/login')->post('/login', [
        'email' => 'not-an-email',
        'password' => 'secret-password',
    ])->assertRedirect('/login')->assertInvalid([
        'email' => 'Please enter a valid email address.',
    ]);
});

it('redirects guests to login and logs authenticated owners out', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();

    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('throttles repeated failed login attempts without revealing account details', function () {
    $email = 'throttle-owner@example.test';
    $throttleKey = strtolower($email).'|127.0.0.1';
    RateLimiter::clear($throttleKey);

    foreach (range(1, 5) as $attempt) {
        $this->from('/login')->post('/login', [
            'email' => $email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors([
            'email' => 'The email or password you entered is incorrect.',
        ]);
    }

    $response = $this->from('/login')->post('/login', [
        'email' => $email,
        'password' => 'incorrect-password',
    ]);

    $response->assertRedirect('/login')->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many login attempts.');

    $this->assertGuest();
    RateLimiter::clear($throttleKey);
});

it('does not reveal whether an unknown owner email exists', function () {
    $response = $this->from('/login')->post('/login', [
        'email' => 'unknown-owner@example.test',
        'password' => 'incorrect-password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'The email or password you entered is incorrect.',
    ]);
    $this->assertGuest();
});
