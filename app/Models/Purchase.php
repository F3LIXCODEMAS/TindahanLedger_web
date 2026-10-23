<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What was bought on one ledger credit (DUMS-36). Purchases are descriptive
 * transaction data for later basket analysis; the linked ledger entry's
 * amount remains what the customer owes. Created only by LedgerService.
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    public const TYPE_UTANG = 'utang';

    /**
     * The store, customer, ledger entry, type, and recorder are set
     * explicitly by LedgerService, never through mass assignment.
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
