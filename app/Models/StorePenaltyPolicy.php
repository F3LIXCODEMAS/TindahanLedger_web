<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\StorePenaltyPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePenaltyPolicy extends Model
{
    /** @use HasFactory<StorePenaltyPolicyFactory> */
    use HasFactory;

    /**
     * Store and version are assigned by the server, never from input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_enabled',
        'fee_cents',
        'grace_days',
        'disclosure_text',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'fee_cents' => 'integer',
            'grace_days' => 'integer',
            'effective_from' => 'date',
            'version' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Whether a credit accepted today falls under this policy.
     */
    public function appliesOn(CarbonImmutable $today): bool
    {
        return $this->is_enabled && $this->effective_from->toDateString() <= $today->toDateString();
    }

    public function fee(): string
    {
        return Money::fromCents($this->fee_cents);
    }

    /**
     * Values stored on a credit so later policy changes never affect it.
     *
     * @return array{policy_id: int, version: int, fee_cents: int, grace_days: int, acknowledged_at: string}
     */
    public function snapshot(): array
    {
        return [
            'policy_id' => $this->getKey(),
            'version' => $this->version,
            'fee_cents' => $this->fee_cents,
            'grace_days' => $this->grace_days,
            'acknowledged_at' => now()->toIso8601String(),
        ];
    }
}
