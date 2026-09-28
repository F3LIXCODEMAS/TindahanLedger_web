<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $running_balance Customer balance immediately after this credit was recorded; later payments do not change this snapshot.
 * @property-read string $outstanding_balance Amount still owed on this ledger entry after its payments.
 */
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
        $amountInCents = Money::toCents($this->amount);
        $paymentsInCents = Money::toCents((string) $this->payments()->sum('amount'));

        return Money::fromCents($amountInCents - $paymentsInCents);
    }
}
