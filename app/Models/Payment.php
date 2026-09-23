<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'ledger_entry_id',
        'amount',
        'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
