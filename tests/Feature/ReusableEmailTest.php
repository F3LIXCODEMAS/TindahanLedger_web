<?php

use App\Mail\ReusableEmail;
use App\Models\Customer;
use App\Services\LedgerService;
use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

it('sends a customizable test email using the shared html and text templates', function () {
    Mail::fake();
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.gmail.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.require_tls' => true,
        'mail.mailers.smtp.username' => 'owner@example.test',
        'mail.mailers.smtp.password' => 'safe-test-app-password',
        'mail.from.address' => 'owner@example.test',
    ]);

    $this->artisan('mail:test', ['recipient' => 'recipient@example.test'])
        ->assertExitCode(Command::SUCCESS);

    Mail::assertSent(ReusableEmail::class, function (ReusableEmail $email): bool {
        $email->assertSeeInHtml('This is a test message from TindahanLedger.');
        $email->assertSeeInText('This is a test message from TindahanLedger.');

        return $email->hasTo('recipient@example.test')
            && $email->subjectLine === 'TindahanLedger email configuration test';
    });
});

it('escapes customizable email content and omits empty additional details', function () {
    $email = new ReusableEmail(
        'Test subject',
        'Hello <script>alert(1)</script>',
        'Message <script>alert(2)</script>',
        '   ',
    );
    $renderedHtml = $email->render();

    expect($renderedHtml)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
    expect($renderedHtml)->not->toContain('Additional details');
    $email->assertSeeInText('Message &lt;script&gt;alert(2)&lt;/script&gt;');
});

it('rejects empty required email content and multiline subjects', function () {
    expect(fn () => new ReusableEmail(' ', 'Hello', 'Body'))
        ->toThrow(InvalidArgumentException::class, 'Email subject, greeting, and message are required.');
    expect(fn () => new ReusableEmail("Subject\nInjected", 'Hello', 'Body'))
        ->toThrow(InvalidArgumentException::class, 'Email subject must be a single line.');
});

it('rejects an invalid recipient without attempting delivery', function () {
    Mail::shouldReceive('to')->never();

    expect(fn () => app(MailDeliveryService::class)->send(
        new ReusableEmail('Subject', 'Hello', 'Body'),
        'not-an-email',
        'test',
    ))->toThrow(InvalidArgumentException::class, 'Recipient must be a valid email address.');
});

it('builds an isolated Gmail mailer with required TLS and the store sender identity', function () {
    $pendingMailer = Mockery::mock(MailerContract::class);
    $pendingMailer->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (ReusableEmail $email): bool {
            return $email->storeName === 'Aling Nena Store'
                && $email->from === [[
                    'name' => 'Aling Nena Store',
                    'address' => 'owner@gmail.com',
                ]];
        }));
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('to')
        ->once()
        ->with('owner@gmail.com')
        ->andReturn(new PendingMail($pendingMailer));

    Mail::shouldReceive('build')
        ->once()
        ->with([
            'transport' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'owner@gmail.com',
            'password' => 'private-app-password',
            'require_tls' => true,
            'timeout' => 15,
        ])
        ->andReturn($mailer);

    $sent = app(MailDeliveryService::class)->sendWithGmail(
        new ReusableEmail('Test subject', 'Hello', 'Body', storeName: 'Aling Nena Store'),
        'owner@gmail.com',
        'owner@gmail.com',
        'Aling Nena Store',
        'private-app-password',
        'settings_test',
    );

    expect($sent)->toBeTrue();
});

it('redacts store Gmail credentials from test email delivery errors', function () {
    $recipient = 'owner@gmail.com';
    Mail::shouldReceive('build')
        ->once()
        ->andThrow(new RuntimeException(
            'Authentication failed for owner@gmail.com with private-app-password.',
        ));
    Log::shouldReceive('error')
        ->once()
        ->with('Email delivery failed.', Mockery::on(function (array $context): bool {
            return $context['notification_type'] === 'settings_test'
                && $context['recipient'] === 'o***@gmail.com'
                && ! str_contains($context['error'], 'owner@gmail.com')
                && ! str_contains($context['error'], 'private-app-password')
                && str_contains($context['error'], '[redacted]');
        }));

    $sent = app(MailDeliveryService::class)->sendWithGmail(
        new ReusableEmail('Test subject', 'Hello', 'Body'),
        $recipient,
        $recipient,
        'Aling Nena Store',
        'private-app-password',
        'settings_test',
    );

    expect($sent)->toBeFalse();
});

it('does not undo recorded credit or payment data when delivery fails', function () {
    config([
        'mail.mailers.smtp.username' => 'sender-secret@example.test',
        'mail.mailers.smtp.password' => 'test-app-password-secret',
    ]);
    $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
    $ledger = app(LedgerService::class);
    $entry = $ledger->recordCredit($customer, ['amount' => '100.00']);
    $ledger->recordCustomerPayment($customer->fresh(), [
        'amount' => '40.00',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $recipient = 'recipient@example.test';

    Mail::shouldReceive('to')
        ->once()
        ->with($recipient)
        ->andThrow(new RuntimeException(
            'Authentication failed for sender-secret@example.test using test-app-password-secret while sending to recipient@example.test.',
        ));
    Log::shouldReceive('error')
        ->once()
        ->with('Email delivery failed.', Mockery::on(function (array $context): bool {
            return $context['notification_type'] === 'test'
                && $context['recipient'] === 'r***@example.test'
                && $context['exception'] === RuntimeException::class
                && ! str_contains($context['error'], 'sender-secret@example.test')
                && ! str_contains($context['error'], 'test-app-password-secret')
                && ! str_contains($context['error'], 'recipient@example.test')
                && str_contains($context['error'], '[redacted-email]');
        }));

    $sent = app(MailDeliveryService::class)->send(
        new ReusableEmail('Subject', 'Hello', 'Body'),
        $recipient,
        'test',
    );

    expect($sent)->toBeFalse();
    $this->assertDatabaseHas('ledger_entries', [
        'id' => $entry->id,
        'customer_id' => $customer->id,
        'amount' => '100.00',
    ]);
    $this->assertDatabaseHas('payments', [
        'customer_id' => $customer->id,
        'ledger_entry_id' => $entry->id,
        'amount' => '40.00',
    ]);
    expect($customer->fresh()->current_balance)->toBe('60.00');
});

it('fails the test command when Gmail SMTP is not configured instead of reporting a false success', function () {
    Mail::fake();
    config(['mail.default' => 'log']);

    $this->artisan('mail:test', ['recipient' => 'recipient@example.test'])
        ->assertExitCode(Command::FAILURE);

    Mail::assertNothingOutgoing();
});

it('rejects an invalid recipient from the test command without sending mail', function () {
    Mail::shouldReceive('to')->never();
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.gmail.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.require_tls' => true,
        'mail.mailers.smtp.username' => 'owner@example.test',
        'mail.mailers.smtp.password' => 'safe-test-app-password',
        'mail.from.address' => 'owner@example.test',
    ]);

    $this->artisan('mail:test', ['recipient' => 'not-an-email'])
        ->assertExitCode(Command::FAILURE);
});
