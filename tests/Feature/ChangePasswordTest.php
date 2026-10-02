<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('requires authentication to view or change the password', function () {
    $this->get(route('password.edit'))->assertRedirect(route('login'));
    $this->put(route('password.update'))->assertRedirect(route('login'));
});

it('shows the change-password form to the authenticated owner', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('password.edit'))
        ->assertOk()
        ->assertSee('Change Password')
        ->assertSee('current_password', false)
        ->assertSee('password_confirmation', false);
});

it('rejects an incorrect current password without changing the saved password', function () {
    $owner = User::factory()->create(['password' => 'Current-owner-pass1']);

    $this->actingAs($owner)->put(route('password.update'), [
        'current_password' => 'incorrect-password',
        'password' => 'New-owner-password2',
        'password_confirmation' => 'New-owner-password2',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('Current-owner-pass1', $owner->fresh()->password))->toBeTrue();
});

it('requires a strong, confirmed, different new password', function () {
    $owner = User::factory()->create(['password' => 'Current-owner-pass1']);
    $this->actingAs($owner);

    $this->put(route('password.update'), [
        'current_password' => 'Current-owner-pass1',
    ])->assertSessionHasErrors('password');

    $this->put(route('password.update'), [
        'current_password' => 'Current-owner-pass1',
        'password' => 'short',
        'password_confirmation' => 'different-short',
    ])->assertSessionHasErrors(['password']);

    $this->put(route('password.update'), [
        'current_password' => 'Current-owner-pass1',
        'password' => 'New-owner-password2',
        'password_confirmation' => 'New-owner-password3',
    ])->assertSessionHasErrors(['password']);

    $this->put(route('password.update'), [
        'current_password' => 'Current-owner-pass1',
        'password' => 'Current-owner-pass1',
        'password_confirmation' => 'Current-owner-pass1',
    ])->assertSessionHasErrors(['password']);
});

it('changes the password securely and keeps the owner authenticated', function () {
    $owner = User::factory()->create(['password' => 'Current-owner-pass1']);

    $this->actingAs($owner)->put(route('password.update'), [
        'current_password' => 'Current-owner-pass1',
        'password' => 'New-owner-password2',
        'password_confirmation' => 'New-owner-password2',
    ])->assertRedirect(route('password.edit'))
        ->assertSessionHas('status', 'Your password has been updated.');

    $this->assertAuthenticatedAs($owner);
    expect(Hash::check('New-owner-password2', $owner->fresh()->password))->toBeTrue();
    expect(Hash::check('Current-owner-pass1', $owner->fresh()->password))->toBeFalse();

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'New-owner-password2',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
});
