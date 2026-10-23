<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A basket of products bought in one transaction, kept for later basket
 * analysis.
 *
 * - `utang` (DUMS-36): describes one ledger credit; the ledger entry's
 *   amount remains what the customer owes. Created by LedgerService.
 * - `cash` (DUMS-37): a sale paid on the spot. It has no ledger entry, may
 *   have no customer (walk-in), and never affects debt, payments, or Suki
 *   Points. Created by CashPurchaseService.
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    public const TYPE_UTANG = 'utang';

    public const TYPE_CASH = 'cash';

    /**
     * The store, customer, ledger entry, type, recorder, and idempotency key
     * are set explicitly by the services, never through mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
        ];
    }

    public function isCash(): bool
    {
        return $this->purchase_type === self::TYPE_CASH;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('id');
    }

    /**
     * Sum of the saved line totals, or null when any item has no price.
     * This is a reference only; the ledger entry amount is what is owed.
     */
    public function referenceSubtotal(): ?string
    {
        if ($this->items->contains(fn (PurchaseItem $item): bool => $item->line_total === null)) {
            return null;
        }

        return Money::fromCents($this->items->sum(fn (PurchaseItem $item): int => Money::toCents($item->line_total)));
    }
}
