<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PurchaseItem;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CreditEntryReceipt extends Mailable
{
    /**
     * @var array<int, string>
     */
    public array $items;

    public function __construct(
        public Customer $customer,
        public LedgerEntry $ledgerEntry,
        public ?string $storeName = null,
    ) {
        $items = $ledgerEntry->purchase?->items
            ->map(fn (PurchaseItem $item): string => $item->description())
            ->all() ?? [];

        foreach ($ledgerEntry->itemized_list ?? [] as $item) {
            if (! is_string($item)) {
                continue;
            }

            foreach (preg_split('/[,;\r\n]+/', $item) ?: [] as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        $this->items = $items;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TindahanLedger – Credit Entry Receipt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.receipts.credit-entry',
            text: 'emails.receipts.credit-entry-text',
            with: [
                'appName' => $this->storeName ?? config('app.name'),
                'transactionDate' => $this->ledgerEntry->created_at?->format('F j, Y g:i A'),
            ],
        );
    }
}
