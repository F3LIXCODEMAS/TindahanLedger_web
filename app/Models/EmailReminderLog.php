<?php

namespace App\Models;

use Database\Factories\EmailReminderLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
