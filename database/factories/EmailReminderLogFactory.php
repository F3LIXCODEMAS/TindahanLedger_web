<?php

namespace Database\Factories;

use App\Models\EmailReminderLog;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailReminderLog>
 */
class EmailReminderLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ledger_entry_id' => LedgerEntry::factory(),
            'customer_id' => fn (array $attributes): int => LedgerEntry::query()
                ->whereKey($attributes['ledger_entry_id'])
                ->value('customer_id'),
            'reminder_type' => EmailReminderLog::TYPE_DUE_DATE,
            'scheduled_reminder_date' => now()->toDateString(),
            'repayment_deadline' => now()->toDateString(),
            'outstanding_amount' => '100.00',
            'delivery_status' => EmailReminderLog::STATUS_SENT,
            'attempt_count' => 1,
            'attempted_at' => now(),
            'sent_at' => now(),
            'failure_reason' => null,
        ];
    }

    public function forLedgerEntry(LedgerEntry $ledgerEntry): static
    {
        return $this->state([
            'customer_id' => $ledgerEntry->customer_id,
            'ledger_entry_id' => $ledgerEntry->getKey(),
            'repayment_deadline' => $ledgerEntry->repayment_deadline?->toDateString() ?? now()->toDateString(),
        ]);
    }
}
