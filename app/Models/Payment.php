<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const KIND_CASH = 'cash';

    /**
     * Suki Points redeemed as a non-cash credit. It reduces the amount owed
     * like a payment but is never counted as cash received.
     */
    public const KIND_LOYALTY_REDEMPTION = 'loyalty_redemption';

    /**
     * Payments are only created by LedgerService; `kind` is never taken from
     * request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'ledger_entry_id',
        'amount',
        'kind',
        'payment_date',
        'note',
        'payment_batch_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'kind' => self::KIND_CASH,
    ];

    public function isLoyaltyRedemption(): bool
    {
        return $this->kind === self::KIND_LOYALTY_REDEMPTION;
    }

    /**
     * @param  Builder<Payment>  $query
     */
    public function scopeCash(Builder $query): void
    {
        $query->where('kind', self::KIND_CASH);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function sukiPointTransaction(): HasOne
    {
        return $this->hasOne(SukiPointTransaction::class);
    }
}
