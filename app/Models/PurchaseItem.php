<?php

namespace App\Models;

use App\Support\Quantity;
use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line of a purchase. The name and unit price are snapshots
 * taken when the credit was recorded, so later catalog changes never alter
 * history. Created only by LedgerService.
 */
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * For example "Rice 1kg × 0.25 @ ₱60.00 = ₱15.00", or "Rice × 2" when
     * the product had no price.
     */
    public function description(): string
    {
        $description = $this->product_name_snapshot.' × '.Quantity::forDisplay($this->quantity);

        if ($this->unit_price_snapshot === null || $this->line_total === null) {
            return $description;
        }

        return $description.' @ ₱'.$this->unit_price_snapshot.' = ₱'.$this->line_total;
    }
}
