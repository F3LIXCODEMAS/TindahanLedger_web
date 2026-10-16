<?php

namespace App\Models;

use Database\Factories\CustomerAccessCredentialFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A QR access credential for the customer portal. The QR code is a bearer
 * credential: holding it proves possession of the code, not who the holder is.
 */
class CustomerAccessCredential extends Model
{
    /** @use HasFactory<CustomerAccessCredentialFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * Credentials are only created and changed by CustomerAccessService.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
        'encrypted_token',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The raw token, or null when it was erased on revocation or can no
     * longer be decrypted (for example after the application key changed).
     */
    public function plainToken(): ?string
    {
        try {
            return $this->encrypted_token;
        } catch (DecryptException) {
            return null;
        }
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
