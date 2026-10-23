<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $running_balance Customer balance immediately after this credit was recorded; later payments do not change this snapshot.
 * @property-read string $outstanding_balance Amount still owed on this ledger entry after its payments.
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const KIND_CREDIT = 'credit';

    /**
     * A one-time late payment fee charged on an overdue credit. It is owed
     * like any debt but has no deadline, so it is never penalised itself.
     */
    public const KIND_LATE_PENALTY = 'late_penalty';

    /**
     * `kind` and `penalty_for_entry_id` are set only by LatePenaltyService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'amount',
        'itemized_list',
        'note',
        'transaction_date',
        'repayment_deadline',
        'penalty_policy_snapshot',
        'running_balance',
        'idempotency_key',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'kind' => self::KIND_CREDIT,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'itemized_list' => 'array',
            'transaction_date' => 'date',
            'repayment_deadline' => 'date',
            'penalty_policy_snapshot' => 'array',
            'penalty_waived_at' => 'datetime',
            'running_balance' => 'decimal:2',
        ];
    }

    public function isLatePenalty(): bool
    {
        return $this->kind === self::KIND_LATE_PENALTY;
    }

    /**
     * @param  Builder<LedgerEntry>  $query
     */
    public function scopeCredits(Builder $query): void
    {
        $query->where('kind', self::KIND_CREDIT);
    }

    /** The late penalty charged on this credit, if any. */
    public function latePenalty(): HasOne
    {
        return $this->hasOne(self::class, 'penalty_for_entry_id');
    }

    /** The credit this penalty was charged on. */
    public function penaltyFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'penalty_for_entry_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function emailReminderLogs(): HasMany
    {
        return $this->hasMany(EmailReminderLog::class);
    }

    /** Structured purchase details recorded with this credit, if any. */
    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    /**
     * Item lines for display: structured purchase items first, then the
     * free-text items exactly as they were entered. Eager load
     * `purchase.items` when listing several entries.
     *
     * @return list<string>
     */
    public function itemDescriptions(): array
    {
        $structuredItems = $this->purchase?->items
            ->map(fn (PurchaseItem $item): string => $item->description())
            ->all() ?? [];
        $freeTextItems = array_map(static fn (mixed $item): string => (string) $item, $this->itemized_list ?? []);

        return array_values([...$structuredItems, ...$freeTextItems]);
    }

    public function getOutstandingBalanceAttribute(): string
    {
        $amountInCents = Money::toCents($this->amount);
        if ($this->relationLoaded('payments')) {
            $paymentsInCents = $this->payments->sum(
                fn (Payment $payment): int => Money::toCents($payment->amount),
            );
        } elseif (array_key_exists('paid_amount', $this->attributes)) {
            $paidAmount = $this->attributes['paid_amount'];
            $paymentsInCents = $paidAmount === null ? 0 : Money::toCents((string) $paidAmount);
        } else {
            $paymentsInCents = Money::toCents((string) $this->payments()->sum('amount'));
        }

        return Money::fromCents($amountInCents - $paymentsInCents);
    }
}
