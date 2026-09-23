<?php

namespace App\Models;

use Database\Factories\SukiPointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SukiPoint extends Model
{
    /** @use HasFactory<SukiPointFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'completed_cycles',
        'current_cycle_count',
        'reward_threshold',
        'points_balance',
        'reward_eligible',
    ];

    protected function casts(): array
    {
        return [
            'reward_eligible' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
