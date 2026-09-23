<?php

namespace App\Models;

use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'amount',
        'itemized_list',
        'transaction_date',
        'repayment_deadline',
        'running_balance',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'itemized_list' => 'array',
            'transaction_date' => 'date',
            'repayment_deadline' => 'date',
            'running_balance' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getOutstandingBalanceAttribute(): string
    {
        return number_format((float) $this->amount - (float) $this->payments()->sum('amount'), 2, '.', '');
    }
}
