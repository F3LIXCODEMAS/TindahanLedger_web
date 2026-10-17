<?php

namespace App\Models;

use Database\Factories\DebtCycleCompletionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed debt cycle: the customer's balance went from owing to
 * exactly zero through a cash payment. The row that completes a streak
 * also records the three-cycle reward it earned.
 */
class DebtCycleCompletion extends Model
{
    /** @use HasFactory<DebtCycleCompletionFactory> */
    use HasFactory;

    public const SOURCE_PAYMENT = 'payment';

    /**
     * A reward granted while migrating customers who were already flagged as
     * reward eligible before cycle history existed.
     */
    public const SOURCE_LEGACY_REWARD = 'legacy_reward';

    /**
     * Rows are written only by LedgerService and the migration.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'streak_position' => 'integer',
            'repaid_cents' => 'integer',
            'reward_points' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function completingPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'completing_payment_id');
    }

    public function earnedReward(): bool
    {
        return $this->reward_points > 0;
    }
}
