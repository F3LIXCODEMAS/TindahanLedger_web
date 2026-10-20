<?php

namespace App\Models;

use Database\Factories\AdminAuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminAuditLog extends Model
{
    /** @use HasFactory<AdminAuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const SUBJECT_STORE = 'store';

    public const SUBJECT_USER = 'user';

    public const ACTION_STORE_CREATED = 'store.created';

    public const ACTION_STORE_UPDATED = 'store.updated';

    public const ACTION_STORE_SUSPENDED = 'store.suspended';

    public const ACTION_STORE_REACTIVATED = 'store.reactivated';

    public const ACTION_STORE_OWNER_REASSIGNED = 'store.owner_reassigned';

    public const ACTION_USER_CREATED = 'user.created';

    public const ACTION_USER_DEACTIVATED = 'user.deactivated';

    public const ACTION_USER_REACTIVATED = 'user.reactivated';

    public const ACTION_USER_TEMPORARY_PASSWORD_SET = 'user.temporary_password_set';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'subject_type',
        'subject_id',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  Builder<AdminAuditLog>  $query
     */
    public function scopeForSubject(Builder $query, string $subjectType, int $subjectId): void
    {
        $query->where('subject_type', $subjectType)->where('subject_id', $subjectId);
    }

    protected function actionLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->action) {
            self::ACTION_STORE_CREATED => 'Store created',
            self::ACTION_STORE_UPDATED => 'Store details updated',
            self::ACTION_STORE_SUSPENDED => 'Store suspended',
            self::ACTION_STORE_REACTIVATED => 'Store reactivated',
            self::ACTION_STORE_OWNER_REASSIGNED => 'Store owner reassigned',
            self::ACTION_USER_CREATED => 'Account created',
            self::ACTION_USER_DEACTIVATED => 'Account deactivated',
            self::ACTION_USER_REACTIVATED => 'Account reactivated',
            self::ACTION_USER_TEMPORARY_PASSWORD_SET => 'Temporary password issued',
            default => Str::headline($this->action),
        });
    }
}
