<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_STORE_OWNER = 'store_owner';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The attributes that are mass assignable.
     *
     * Role, status, and password-change flags are deliberately excluded so
     * they can only be set explicitly by trusted server-side code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    /**
     * @var array<string, string|bool>
     */
    protected $attributes = [
        'role' => self::ROLE_STORE_OWNER,
        'status' => self::STATUS_ACTIVE,
        'must_change_password' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Usernames are compared case-insensitively, so they are stored trimmed,
     * with single spaces, and in lowercase.
     */
    public static function normalizeUsername(string $username): string
    {
        return Str::lower(Str::squish($username));
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : self::normalizeUsername($value),
        );
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_user_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isStoreOwner(): bool
    {
        return $this->role === self::ROLE_STORE_OWNER;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function storeEmailSetting(): HasOne
    {
        return $this->hasOne(StoreEmailSetting::class);
    }
}
