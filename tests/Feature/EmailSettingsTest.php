<?php

use App\Mail\ReusableEmail;
use App\Models\Store;
use App\Models\StoreEmailSetting;
use App\Services\MailDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('requires an authenticated owner to access email settings', function () {
    $this->get(route('email-settings.edit'))->assertRedirect(route('login'));
    $this->put(route('email-settings.update'))->assertRedirect(route('login'));
    $this->post(route('email-settings.test'))->assertRedirect(route('login'));
});

it('shows the email settings form and app password guide to the owner', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('email-settings.edit'))
        ->assertOk()
        ->assertSee('Email notifications')
        ->assertSee('Your Gmail address')
        ->assertSee('Google App Password')
        ->assertSee('2-Step Verification')
        ->assertSee('https://support.google.com/accounts/answer/185833', false)
        ->assertSee('/settings/email-notifications/test', false);
});

it('saves an owner email configuration with an encrypted app password', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;

    $this->actingAs($owner)
        ->put(route('email-settings.update'), [
            'store_name' => 'Aling Nena Store',
            'sender_email' => 'nena@gmail.com',
            'app_password' => 'abcd efgh ijkl mnop',
        ])
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHas('status', 'Your email settings have been saved.');

    $settings = $owner->fresh()->storeEmailSetting;
    $storedCiphertext = DB::table('store_email_settings')
        ->where('user_id', $owner->id)
        ->value('smtp_app_password');

    expect($settings->store_name)->toBe('Aling Nena Store');
    expect($settings->sender_email)->toBe('nena@gmail.com');
    expect($settings->smtp_app_password)->toBe('abcdefghijklmnop');
    expect($storedCiphertext)->not->toBe('abcdefghijklmnop');
    expect(array_key_exists('smtp_app_password', $settings->toArray()))->toBeFalse();
});

it('keeps the existing encrypted app password when the owner edits other settings', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    $settings = StoreEmailSetting::factory()->for($owner)->create([
        'store_name' => 'Old Store Name',
        'sender_email' => 'old@gmail.com',
        'smtp_app_password' => 'existing-app-password',
    ]);

    $this->actingAs($owner)
        ->put(route('email-settings.update'), [
            'store_name' => 'New Store Name',
            'sender_email' => 'new@gmail.com',
            'app_password' => '',
        ])
        ->assertRedirect(route('email-settings.edit'));

    expect($settings->fresh()->store_name)->toBe('New Store Name');
    expect($settings->fresh()->sender_email)->toBe('new@gmail.com');
    expect($settings->fresh()->smtp_app_password)->toBe('existing-app-password');
});

it('never renders a saved app password in the settings page', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create([
        'smtp_app_password' => 'never-render-this-secret',
    ]);

    $this->actingAs($owner)
        ->get(route('email-settings.edit'))
        ->assertOk()
        ->assertDontSee('never-render-this-secret');
});

it('rejects an invalid sender email and does not flash the app password', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;

    $this->actingAs($owner)
        ->from(route('email-settings.edit'))
        ->put(route('email-settings.update'), [
            'store_name' => 'Aling Nena Store',
            'sender_email' => 'not-an-email',
            'app_password' => 'private-app-password',
        ])
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHasErrors([
            'sender_email' => 'Please enter a valid email address.',
        ])
        ->assertSessionMissing('_old_input.app_password');
});

it('requires an app password when creating the first email configuration', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;

    $this->actingAs($owner)
        ->from(route('email-settings.edit'))
        ->put(route('email-settings.update'), [
            'store_name' => 'Aling Nena Store',
            'sender_email' => 'nena@gmail.com',
        ])
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHasErrors([
            'app_password' => 'Please enter your Google App Password.',
        ]);

    $this->assertDatabaseMissing('store_email_settings', ['user_id' => $owner->id]);
});

it('rejects line breaks in the store name used as the email sender name', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;

    $this->actingAs($owner)
        ->from(route('email-settings.edit'))
        ->put(route('email-settings.update'), [
            'store_name' => "Aling Nena\nInjected Header",
            'sender_email' => 'nena@gmail.com',
            'app_password' => 'private-app-password',
        ])
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHasErrors([
            'store_name' => 'Please enter your store name on one line.',
        ]);

    $this->assertDatabaseMissing('store_email_settings', ['user_id' => $owner->id]);
});

it('sends a test email to the saved Gmail address using that owner configuration', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    $settings = StoreEmailSetting::factory()->for($owner)->create([
        'store_name' => 'Aling Nena Store',
        'sender_email' => 'nena@gmail.com',
        'smtp_app_password' => 'private-app-password',
    ]);
    $delivery = Mockery::mock(MailDeliveryService::class);
    $delivery->shouldReceive('sendWithGmail')
        ->once()
        ->withArgs(function (
            ReusableEmail $email,
            string $recipient,
            string $senderEmail,
            string $senderName,
            string $appPassword,
            string $notificationType,
        ): bool {
            return $recipient === 'nena@gmail.com'
                && $senderEmail === 'nena@gmail.com'
                && $senderName === 'Aling Nena Store'
                && $appPassword === 'private-app-password'
                && $notificationType === 'settings_test'
                && $email->storeName === 'Aling Nena Store';
        })
        ->andReturn(true);
    $this->instance(MailDeliveryService::class, $delivery);

    $this->actingAs($owner)
        ->post(route('email-settings.test'))
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHas('status', 'Connected successfully! We sent a test email to your Gmail inbox. Check Spam if it is not there.');

    expect($settings->fresh()->hasSmtpPassword())->toBeTrue();
});

it('shows an error instead of a success message when the test email cannot be sent', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create();
    $delivery = Mockery::mock(MailDeliveryService::class);
    $delivery->shouldReceive('sendWithGmail')->once()->andReturn(false);
    $this->instance(MailDeliveryService::class, $delivery);

    $this->actingAs($owner)
        ->post(route('email-settings.test'))
        ->assertRedirect(route('email-settings.edit'))
        ->assertSessionHasErrors([
            'email_test' => 'We could not send the test email. Check your Gmail address and App Password, then try again.',
        ])
        ->assertSessionMissing('status');
});

it('does not send a test email before an app password is saved', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    $delivery = Mockery::mock(MailDeliveryService::class);
    $delivery->shouldNotReceive('sendWithGmail');
    $this->instance(MailDeliveryService::class, $delivery);

    $this->actingAs($owner)
        ->post(route('email-settings.test'))
        ->assertRedirect()
        ->assertSessionHasErrors([
            'email_test' => 'Save your Gmail address and App Password before sending a test email.',
        ]);
});

it('keeps email settings isolated between owner accounts', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    $otherOwner = Store::factory()->create()->owner;
    StoreEmailSetting::factory()->for($otherOwner)->create([
        'store_name' => 'Private Other Store',
        'sender_email' => 'other@gmail.com',
        'smtp_app_password' => 'other-owner-secret',
    ]);

    $this->actingAs($owner)
        ->get(route('email-settings.edit'))
        ->assertOk()
        ->assertDontSee('Private Other Store')
        ->assertDontSee('other@gmail.com');
});

it('limits repeated test email requests for the same owner', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create();
    $delivery = Mockery::mock(MailDeliveryService::class);
    $delivery->shouldReceive('sendWithGmail')->times(3)->andReturn(true);
    $this->instance(MailDeliveryService::class, $delivery);
    $this->actingAs($owner);

    foreach (range(1, 3) as $attempt) {
        $this->post(route('email-settings.test'))->assertRedirect();
    }

    $this->post(route('email-settings.test'))->assertTooManyRequests();
});

it('asks the owner to re-enter an app password that can no longer be decrypted', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create();
    DB::table('store_email_settings')
        ->where('user_id', $owner->id)
        ->update(['smtp_app_password' => 'ciphertext-from-an-old-app-key']);
    $delivery = Mockery::mock(MailDeliveryService::class);
    $delivery->shouldNotReceive('sendWithGmail');
    $this->instance(MailDeliveryService::class, $delivery);

    $this->actingAs($owner)
        ->get(route('email-settings.edit'))
        ->assertOk()
        ->assertSee('Your saved App Password can no longer be used. Please enter it again and save.');

    $this->actingAs($owner)
        ->from(route('email-settings.edit'))
        ->put(route('email-settings.update'), [
            'store_name' => 'Aling Nena Store',
            'sender_email' => 'nena@gmail.com',
            'app_password' => '',
        ])
        ->assertSessionHasErrors([
            'app_password' => 'Please enter your Google App Password.',
        ]);

    $this->actingAs($owner)
        ->post(route('email-settings.test'))
        ->assertSessionHasErrors([
            'email_test' => 'Save your Gmail address and App Password before sending a test email.',
        ]);
});

it('sends owner emails through the owner saved Gmail account and store name', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    StoreEmailSetting::factory()->for($owner)->create([
        'store_name' => 'Aling Nena Store',
        'sender_email' => 'nena@gmail.com',
        'smtp_app_password' => 'private-app-password',
    ]);
    $mailer = Mockery::mock();
    $mailer->shouldReceive('to')->once()->with('customer@example.test')->andReturnSelf();
    $mailer->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn (ReusableEmail $email): bool => $email->from[0]['address'] === 'nena@gmail.com'
            && $email->from[0]['name'] === 'Aling Nena Store'
            && $email->storeName === 'Aling Nena Store'));
    Mail::shouldReceive('build')
        ->once()
        ->with(Mockery::on(fn (array $config): bool => $config['host'] === 'smtp.gmail.com'
            && $config['username'] === 'nena@gmail.com'
            && $config['password'] === 'private-app-password'
            && $config['require_tls'] === true))
        ->andReturn($mailer);

    $sent = app(MailDeliveryService::class)->sendAsOwner(
        $owner,
        new ReusableEmail('Payment reminder', 'Hello!', 'You have a balance due.'),
        'customer@example.test',
        'debt_reminder',
    );

    expect($sent)->toBeTrue();
});

it('does not send owner emails when the owner has not configured email settings', function () {
    $store = Store::factory()->create();
    $owner = $store->owner;
    Mail::shouldReceive('build')->never();
    Mail::shouldReceive('to')->never();

    $sent = app(MailDeliveryService::class)->sendAsOwner(
        $owner,
        new ReusableEmail('Payment reminder', 'Hello!', 'You have a balance due.'),
        'customer@example.test',
        'debt_reminder',
    );

    expect($sent)->toBeFalse();
});
