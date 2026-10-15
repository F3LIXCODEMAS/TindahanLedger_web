<?php

namespace App\Models;

use Database\Factories\StoreEmailSettingFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreEmailSetting extends Model
{
    /** @use HasFactory<StoreEmailSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_name',
        'sender_email',
        'smtp_app_password',
    ];

    protected $hidden = [
        'smtp_app_password',
    ];

    protected function casts(): array
    {
        return [
            'smtp_app_password' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the decrypted App Password, or null when it is missing or can no
     * longer be decrypted (for example after the application key changed).
     */
    public function appPassword(): ?string
    {
        try {
            $appPassword = $this->smtp_app_password;
        } catch (DecryptException) {
            return null;
        }

        return filled($appPassword) ? $appPassword : null;
    }

    public function hasSmtpPassword(): bool
    {
        return $this->appPassword() !== null;
    }

    public function needsPasswordReentry(): bool
    {
        return filled($this->getRawOriginal('smtp_app_password')) && ! $this->hasSmtpPassword();
    }
}
