<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Customers can sign in to the read-only self-service portal through the
 * `customer` guard using a QR access credential. They have no password and
 * no user account, and the guard never grants owner or admin access.
 */
class Customer extends Model implements AuthenticatableContract
{
    /** @use HasFactory<CustomerFactory> */
    use Authenticatable, HasFactory;

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    protected $fillable = [
        'full_name',
        'contact_number',
        'email',
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function emailReminderLogs(): HasMany
    {
        return $this->hasMany(EmailReminderLog::class);
    }

    public function accessCredentials(): HasMany
    {
        return $this->hasMany(CustomerAccessCredential::class);
    }

    public function activeAccessCredential(): HasOne
    {
        return $this->hasOne(CustomerAccessCredential::class)
            ->where('status', CustomerAccessCredential::STATUS_ACTIVE);
    }

    public function sukiPoints(): HasOne
    {
        return $this->hasOne(SukiPoint::class);
    }

    public function sukiPointTransactions(): HasMany
    {
        return $this->hasMany(SukiPointTransaction::class);
    }

    public function debtCycleCompletions(): HasMany
    {
        return $this->hasMany(DebtCycleCompletion::class);
    }
}
