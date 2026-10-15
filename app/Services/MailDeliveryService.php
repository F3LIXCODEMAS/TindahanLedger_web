<?php

namespace App\Services;

use App\Mail\CreditEntryReceipt;
use App\Mail\PaymentReceipt;
use App\Mail\ReusableEmail;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class MailDeliveryService
{
    public function send(Mailable $mailable, string $recipient, string $notificationType): bool
    {
        $recipient = $this->validatedRecipient($recipient);

        try {
            Mail::to($recipient)->send($mailable);

            return true;
        } catch (Throwable $exception) {
            $this->logFailure($exception, $recipient, $notificationType);

            return false;
        }
    }

    /**
     * Send an email from the owner's saved Gmail account.
     *
     * Returns false without attempting delivery when the owner has not saved
     * usable email settings, so mail is never silently sent from another account.
     */
    public function sendAsOwner(User $owner, Mailable $mailable, ?string $recipient, string $notificationType): bool
    {
        if (! $this->isValidRecipient($recipient)) {
            return false;
        }

        $recipient = $this->validatedRecipient($recipient);

        try {
            $settings = $owner->storeEmailSetting;
            $appPassword = $settings?->appPassword();

            if ($settings === null || $appPassword === null) {
                Log::warning('Email not sent because store email settings are not configured.', [
                    'notification_type' => $notificationType,
                    'owner_id' => $owner->getKey(),
                    'needs_password_reentry' => $settings?->needsPasswordReentry() ?? false,
                ]);

                return false;
            }

            if ($mailable instanceof ReusableEmail && $mailable->storeName === null) {
                $mailable->storeName = $settings->store_name;
            }

            if ($mailable instanceof CreditEntryReceipt || $mailable instanceof PaymentReceipt) {
                $mailable->storeName = $settings->store_name;
            }

            return $this->sendWithGmail(
                $mailable,
                $recipient,
                $settings->sender_email,
                $settings->store_name,
                $appPassword,
                $notificationType,
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception, $recipient, $notificationType);

            return false;
        }
    }

    public function sendWithGmail(
        Mailable $mailable,
        string $recipient,
        string $senderEmail,
        string $senderName,
        string $appPassword,
        string $notificationType,
    ): bool {
        $recipient = $this->validatedRecipient($recipient);

        try {
            Mail::build([
                'transport' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'scheme' => 'smtp',
                'username' => $senderEmail,
                'password' => $appPassword,
                'require_tls' => true,
                'timeout' => 15,
            ])->to($recipient)->send($mailable->from($senderEmail, $senderName));

            return true;
        } catch (Throwable $exception) {
            $this->logFailure(
                $exception,
                $recipient,
                $notificationType,
                [$senderEmail, $appPassword],
            );

            return false;
        }
    }

    private function validatedRecipient(string $recipient): string
    {
        $recipient = trim($recipient);

        if (! $this->isValidRecipient($recipient)) {
            throw new InvalidArgumentException('Recipient must be a valid email address.');
        }

        return $recipient;
    }

    private function isValidRecipient(?string $recipient): bool
    {
        if (! is_string($recipient) || trim($recipient) === '') {
            return false;
        }

        $domain = Str::afterLast(trim($recipient), '@');

        if (Str::endsWith(Str::lower($domain), '.invalid')) {
            return false;
        }

        return Validator::make(
            ['recipient' => trim($recipient)],
            ['recipient' => ['required', 'email', 'max:255']],
        )->passes();
    }

    /**
     * @param  array<int, string|null>  $additionalSensitiveValues
     */
    private function logFailure(
        Throwable $exception,
        string $recipient,
        string $notificationType,
        array $additionalSensitiveValues = [],
    ): void {
        Log::error('Email delivery failed.', [
            'notification_type' => $notificationType,
            'recipient' => $this->maskRecipient($recipient),
            'exception' => $exception::class,
            'error' => $this->safeErrorMessage($exception, $additionalSensitiveValues),
        ]);
    }

    private function maskRecipient(string $recipient): string
    {
        [$localPart, $domain] = explode('@', $recipient, 2);

        return Str::substr($localPart, 0, 1).'***@'.$domain;
    }

    /**
     * @param  array<int, string|null>  $additionalSensitiveValues
     */
    private function safeErrorMessage(Throwable $exception, array $additionalSensitiveValues = []): string
    {
        $message = $exception->getMessage();
        $sensitiveValues = array_merge($additionalSensitiveValues, [
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.url'),
        ]);
        $mailUrl = config('mail.mailers.smtp.url');

        if (is_string($mailUrl) && $mailUrl !== '') {
            $urlParts = parse_url($mailUrl);

            if (is_array($urlParts)) {
                foreach (['user', 'pass'] as $credentialPart) {
                    if (isset($urlParts[$credentialPart])) {
                        $sensitiveValues[] = rawurldecode($urlParts[$credentialPart]);
                    }
                }
            }
        }

        foreach ($sensitiveValues as $sensitiveValue) {
            if (! is_string($sensitiveValue) || $sensitiveValue === '') {
                continue;
            }

            $message = str_replace(
                array_unique([$sensitiveValue, rawurlencode($sensitiveValue)]),
                '[redacted]',
                $message,
            );
        }

        $message = preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '[redacted-email]',
            $message,
        ) ?? 'Mail transport failed with an unparseable error message.';
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? $message;

        return Str::limit($message, 500);
    }
}
