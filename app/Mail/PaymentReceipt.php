<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReceipt extends Mailable
{
    public function __construct(
        public Customer $customer,
        public Payment $payment,
        public string $amount,
        public string $remainingBalance,
        public bool $debtCycleCompleted,
        public ?string $storeName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TindahanLedger – Payment Receipt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.receipts.payment',
            text: 'emails.receipts.payment-text',
            with: [
                'appName' => $this->storeName ?? config('app.name'),
                'transactionDate' => $this->payment->created_at?->format('F j, Y g:i A'),
            ],
        );
    }
}
