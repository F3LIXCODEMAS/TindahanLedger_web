<?php

namespace App\Models;

use Database\Factories\EmailReminderLogFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailReminderLog extends Model
{
    /** @use HasFactory<EmailReminderLogFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const TYPE_ADVANCE = 'advance';

    public const TYPE_DUE_DATE = 'due_date';

    public const TYPE_OVERDUE = 'overdue';

    protected $fillable = [
        'customer_id',
        'ledger_entry_id',
        'reminder_type',
        'scheduled_reminder_date',
        'repayment_deadline',
        'outstanding_amount',
        'delivery_status',
        'attempt_count',
        'attempted_at',
        'sent_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_reminder_date' => 'date',
            'repayment_deadline' => 'date',
            'outstanding_amount' => 'decimal:2',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected function reminderTypeLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->reminder_type) {
            self::TYPE_ADVANCE => 'Advance (three days before due)',
            self::TYPE_DUE_DATE => 'Due-date reminder',
            self::TYPE_OVERDUE => 'Overdue reminder',
            default => Str::headline($this->reminder_type),
        });
    }

    protected function failureDescription(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->failure_reason === null
            ? null
            : match ($this->failure_reason) {
                'customer_email_missing' => 'Skipped because the customer has no email address.',
                'customer_email_invalid' => 'Skipped because the customer email address is invalid.',
                'sender_not_configured' => 'Could not send because store email settings are not configured.',
                'multiple_store_email_settings' => 'Could not send because multiple store email configurations exist.',
                'email_delivery_failed' => 'Email delivery failed. It will be retried on a future run.',
                default => 'Reminder processing failed. It will be retried on a future run.',
            });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
