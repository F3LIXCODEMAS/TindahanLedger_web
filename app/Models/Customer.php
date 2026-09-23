<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'full_name',
        'contact_number',
        'residential_landmark',
        'credit_limit',
        'current_balance',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'current_balance' => 'decimal:2',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sukiPoints(): HasOne
    {
        return $this->hasOne(SukiPoint::class);
    }
}
