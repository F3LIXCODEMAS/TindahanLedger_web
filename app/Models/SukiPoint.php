<?php

namespace App\Models;

use Database\Factories\SukiPointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SukiPoint extends Model
{
    public const CENTS_PER_POINT = 10000;

    /** @use HasFactory<SukiPointFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'completed_cycles',
        'current_cycle_count',
        'reward_threshold',
        'points_balance',
        'repayment_remainder_cents',
        'reward_eligible',
    ];

    protected $attributes = [
        'completed_cycles' => 0,
        'current_cycle_count' => 0,
        'reward_threshold' => 3,
        'points_balance' => 0,
        'repayment_remainder_cents' => 0,
        'reward_eligible' => false,
    ];

    protected function casts(): array
    {
        return [
            'completed_cycles' => 'integer',
            'current_cycle_count' => 'integer',
            'reward_threshold' => 'integer',
            'points_balance' => 'integer',
            'repayment_remainder_cents' => 'integer',
            'reward_eligible' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SukiPointTransaction::class, 'customer_id', 'customer_id');
    }
}
