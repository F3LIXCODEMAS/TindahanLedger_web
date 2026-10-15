<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use InvalidArgumentException;

class ReusableEmail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $greeting,
        public string $body,
        public ?string $details = null,
        public ?string $storeName = null,
    ) {
        if (trim($subjectLine) === '' || trim($greeting) === '' || trim($body) === '') {
            throw new InvalidArgumentException('Email subject, greeting, and message are required.');
        }

        if (str_contains($subjectLine, "\r") || str_contains($subjectLine, "\n")) {
            throw new InvalidArgumentException('Email subject must be a single line.');
        }

        $this->subjectLine = trim($subjectLine);
        $this->greeting = trim($greeting);
        $this->body = trim($body);
        $this->details = $details === null || trim($details) === '' ? null : trim($details);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reusable-message',
            text: 'emails.reusable-message-text',
            with: [
                'appName' => $this->storeName ?? config('app.name'),
                'greeting' => $this->greeting,
                'body' => $this->body,
                'details' => $this->details,
            ],
        );
    }
}
