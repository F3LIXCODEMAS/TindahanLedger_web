<?php

namespace App\Models;

use Database\Factories\SukiPointTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SukiPointTransaction extends Model
{
    /** @use HasFactory<SukiPointTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'payment_id',
        'paid_amount_cents',
        'points_awarded',
        'remainder_before_cents',
        'remainder_after_cents',
    ];

    protected function casts(): array
    {
        return [
            'paid_amount_cents' => 'integer',
            'points_awarded' => 'integer',
            'remainder_before_cents' => 'integer',
            'remainder_after_cents' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
