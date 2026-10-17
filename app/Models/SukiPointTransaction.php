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

    public const TYPE_EARNED = 'earned';

    public const TYPE_REDEEMED = 'redeemed';

    protected $fillable = [
        'customer_id',
        'payment_id',
        'transaction_type',
        'paid_amount_cents',
        'points_awarded',
        'points_redeemed',
        'redeemed_amount_cents',
        'remainder_before_cents',
        'remainder_after_cents',
        'recorded_by_user_id',
    ];

    /**
     * @var array<string, string|int>
     */
    protected $attributes = [
        'transaction_type' => self::TYPE_EARNED,
        'points_redeemed' => 0,
        'redeemed_amount_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'paid_amount_cents' => 'integer',
            'points_awarded' => 'integer',
            'points_redeemed' => 'integer',
            'redeemed_amount_cents' => 'integer',
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
