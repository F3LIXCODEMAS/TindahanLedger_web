<?php

namespace App\Console\Commands;

use App\Mail\ReusableEmail;
use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SendTestEmail extends Command
{
    protected $signature = 'mail:test {recipient : Email address that should receive the test message}';

    protected $description = 'Send a test email using the configured Gmail SMTP connection';

    public function handle(MailDeliveryService $mailDeliveryService): int
    {
        if (! $this->hasGmailSmtpConfiguration()) {
            $this->components->error(
                'Gmail SMTP is not fully configured. Set the mail values in your ignored .env file, then clear cached configuration if needed.',
            );

            return self::FAILURE;
        }

        try {
            $sent = $mailDeliveryService->send(
                new ReusableEmail(
                    'TindahanLedger email configuration test',
                    'Hello!',
                    'This is a test message from TindahanLedger. If you received it, outgoing email is configured.',
                    'Future debt reminders and payment notifications will use this reusable email foundation.',
                ),
                (string) $this->argument('recipient'),
                'test',
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $sent) {
            $this->components->error('The test email could not be sent. Check the application log for diagnostic details.');

            return self::FAILURE;
        }

        $this->components->info('The test email was accepted by the configured SMTP transport.');

        return self::SUCCESS;
    }

    private function hasGmailSmtpConfiguration(): bool
    {
        return config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.host') === 'smtp.gmail.com'
            && (int) config('mail.mailers.smtp.port') === 587
            && config('mail.mailers.smtp.scheme') === 'smtp'
            && config('mail.mailers.smtp.require_tls') === true
            && filled(config('mail.mailers.smtp.username'))
            && filled(config('mail.mailers.smtp.password'))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }
}
