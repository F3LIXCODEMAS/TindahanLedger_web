<?php

namespace App\Services;

use App\Mail\ReusableEmail;
use App\Models\EmailReminderLog;
use App\Models\LedgerEntry;
use App\Models\StoreEmailSetting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentReminderService
{
    private const LOCK_SECONDS = 300;

    /**
     * @var list<string>
     */
    private const RETRYABLE_SKIP_REASONS = [
        'customer_email_missing',
        'customer_email_invalid',
    ];

    public function __construct(
        private readonly ReminderSchedule $reminderSchedule,
        private readonly MailDeliveryService $mailDeliveryService,
    ) {}

    /**
     * @return array{processed: int, sent: int, skipped: int, failed: int}
     */
    public function sendDueReminders(?CarbonImmutable $date = null): array
    {
        $timezone = (string) config('app.timezone');
        $today = ($date ?? CarbonImmutable::now($timezone))
            ->setTimezone($timezone)
            ->startOfDay();

        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $triggers = $this->findTriggers($today);

        if ($triggers === []) {
            return $summary;
        }

        $settings = StoreEmailSetting::query()->with('user')->get();
        $owner = $settings->count() === 1 ? $settings->first()?->user : null;
        $hasAmbiguousSettings = $settings->count() > 1;

        if ($hasAmbiguousSettings) {
            Log::warning('Repayment reminders were not sent because multiple store email settings exist.', [
                'settings_count' => $settings->count(),
            ]);
        }

        foreach ($triggers as $trigger) {
            $summary['processed']++;
            $result = $this->processTrigger(
                $trigger['ledger_entry'],
                $trigger['reminder_type'],
                $trigger['scheduled_date'],
                $owner,
                $hasAmbiguousSettings,
            );
            $summary[$result]++;
        }

        return $summary;
    }

    /**
     * @return array<string, array{
     *     ledger_entry: LedgerEntry,
     *     reminder_type: string,
     *     scheduled_date: CarbonImmutable
     * }>
     */
    private function findTriggers(CarbonImmutable $today): array
    {
        $triggers = [];
        $candidateEntries = LedgerEntry::query()
            ->with('customer:id,full_name,email')
            ->withSum('payments as paid_amount', 'amount')
            ->whereNotNull('repayment_deadline')
            ->whereDate('repayment_deadline', '<=', $today->addDays(3)->toDateString())
            ->whereRaw(
                'ledger_entries.amount > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.ledger_entry_id = ledger_entries.id), 0)',
            )
            ->orderBy('repayment_deadline')
            ->orderBy('id')
            ->get();

        foreach ($candidateEntries as $entry) {
            $deadline = CarbonImmutable::parse($entry->repayment_deadline->toDateString(), $today->timezone);
            $reminderType = $this->reminderSchedule->reminderTypeFor($deadline, $today);

            if ($reminderType === null || Money::toCents($entry->outstanding_balance) <= 0) {
                continue;
            }

            $this->addTrigger($triggers, $entry, $reminderType, $today);
        }

        $this->addRetryTriggers($triggers, $today);

        return $triggers;
    }

    /**
     * @param  array<string, array{
     *     ledger_entry: LedgerEntry,
     *     reminder_type: string,
     *     scheduled_date: CarbonImmutable
     * }>  $triggers
     */
    private function addRetryTriggers(array &$triggers, CarbonImmutable $today): void
    {
        $retryableLogs = EmailReminderLog::query()
            ->with(['ledgerEntry.customer:id,full_name,email', 'ledgerEntry.payments'])
            ->whereDate('scheduled_reminder_date', '<=', $today->toDateString())
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('delivery_status', [
                        EmailReminderLog::STATUS_PENDING,
                        EmailReminderLog::STATUS_SENDING,
                        EmailReminderLog::STATUS_FAILED,
                    ])
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('delivery_status', EmailReminderLog::STATUS_SKIPPED)
                            ->whereIn('failure_reason', self::RETRYABLE_SKIP_REASONS);
                    });
            })
            ->orderBy('id')
            ->get();

        foreach ($retryableLogs as $log) {
            $entry = $log->ledgerEntry;

            if (
                $entry === null
                || $entry->repayment_deadline === null
                || $entry->repayment_deadline->toDateString() !== $log->repayment_deadline->toDateString()
                || Money::toCents($entry->outstanding_balance) <= 0
            ) {
                continue;
            }

            $scheduledDate = CarbonImmutable::parse(
                $log->scheduled_reminder_date->toDateString(),
                $today->timezone,
            );
            $this->addTrigger($triggers, $entry, $log->reminder_type, $scheduledDate);
        }
    }

    /**
     * @param  array<string, array{
     *     ledger_entry: LedgerEntry,
     *     reminder_type: string,
     *     scheduled_date: CarbonImmutable
     * }>  $triggers
     */
    private function addTrigger(
        array &$triggers,
        LedgerEntry $entry,
        string $reminderType,
        CarbonImmutable $scheduledDate,
    ): void {
        $key = implode(':', [
            $entry->getKey(),
            $reminderType,
            $scheduledDate->toDateString(),
        ]);

        $triggers[$key] = [
            'ledger_entry' => $entry,
            'reminder_type' => $reminderType,
            'scheduled_date' => $scheduledDate,
        ];
    }

    private function processTrigger(
        LedgerEntry $entry,
        string $reminderType,
        CarbonImmutable $scheduledDate,
        ?User $owner,
        bool $hasAmbiguousSettings,
    ): string {
        $identity = [
            'ledger_entry_id' => $entry->getKey(),
            'reminder_type' => $reminderType,
            'scheduled_reminder_date' => $scheduledDate->startOfDay(),
        ];
        $lock = Cache::lock(implode(':', [
            'repayment-reminder',
            $entry->getKey(),
            $reminderType,
            $scheduledDate->toDateString(),
        ]), self::LOCK_SECONDS);

        try {
            if (! $lock->get()) {
                return 'skipped';
            }
        } catch (Throwable $exception) {
            Log::error('Could not acquire the repayment reminder lock.', [
                'ledger_entry_id' => $entry->getKey(),
                'reminder_type' => $reminderType,
                'exception' => $exception,
            ]);

            return 'failed';
        }

        try {
            $recipient = $entry->customer?->email;

            if (! $this->mailDeliveryService->isValidRecipient($recipient)) {
                $failureReason = filled($recipient)
                    ? 'customer_email_invalid'
                    : 'customer_email_missing';
                $this->recordSkipped($entry, $identity, $failureReason);

                return 'skipped';
            }

            $log = $this->reserveTrigger($entry, $identity, $reminderType, $scheduledDate);

            if ($log === null) {
                return 'skipped';
            }

            if ($hasAmbiguousSettings) {
                $this->markFailed($log, 'multiple_store_email_settings');

                return 'failed';
            }

            if ($owner === null) {
                $this->markFailed($log, 'sender_not_configured');

                return 'failed';
            }

            $typeLabel = match ($reminderType) {
                EmailReminderLog::TYPE_ADVANCE => 'Upcoming payment reminder',
                EmailReminderLog::TYPE_DUE_DATE => 'Payment due today',
                EmailReminderLog::TYPE_OVERDUE => 'Overdue payment reminder',
            };
            $body = match ($reminderType) {
                EmailReminderLog::TYPE_ADVANCE => 'This is a friendly reminder that your outstanding balance is due in three days.',
                EmailReminderLog::TYPE_DUE_DATE => 'This is a reminder that your outstanding balance is due today.',
                EmailReminderLog::TYPE_OVERDUE => 'This is a reminder that your outstanding balance is overdue.',
            };
            $details = implode("\n", [
                'Amount remaining: ₱'.number_format((float) $entry->outstanding_balance, 2),
                'Repayment deadline: '.$entry->repayment_deadline->format('F j, Y'),
            ]);
            $sent = $this->mailDeliveryService->sendAsOwner(
                $owner,
                new ReusableEmail(
                    subjectLine: 'TindahanLedger – '.$typeLabel,
                    greeting: 'Hello '.$entry->customer->full_name.',',
                    body: $body,
                    details: $details,
                ),
                $recipient,
                'repayment_reminder',
            );

            if (! $sent) {
                $this->markFailed($log, 'email_delivery_failed');

                return 'failed';
            }

            $log->forceFill([
                'delivery_status' => EmailReminderLog::STATUS_SENT,
                'sent_at' => now(config('app.timezone')),
                'failure_reason' => null,
            ])->save();

            return 'sent';
        } catch (Throwable $exception) {
            Log::error('Repayment reminder processing failed.', [
                'ledger_entry_id' => $entry->getKey(),
                'reminder_type' => $reminderType,
                'exception' => $exception,
            ]);

            if (isset($log)) {
                $this->markFailed($log, 'processing_error');
            }

            return 'failed';
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{ledger_entry_id: int|string, reminder_type: string, scheduled_reminder_date: CarbonImmutable}  $identity
     */
    private function reserveTrigger(
        LedgerEntry $entry,
        array $identity,
        string $reminderType,
        CarbonImmutable $scheduledDate,
    ): ?EmailReminderLog {
        return DB::transaction(function () use ($entry, $identity): ?EmailReminderLog {
            $log = EmailReminderLog::query()->firstOrCreate($identity, [
                'customer_id' => $entry->customer_id,
                'repayment_deadline' => $entry->repayment_deadline->toDateString(),
                'outstanding_amount' => $entry->outstanding_balance,
                'delivery_status' => EmailReminderLog::STATUS_PENDING,
                'attempt_count' => 0,
            ]);
            $log = EmailReminderLog::query()->lockForUpdate()->findOrFail($log->getKey());

            if ($log->delivery_status === EmailReminderLog::STATUS_SENT) {
                return null;
            }

            if (
                $log->delivery_status === EmailReminderLog::STATUS_SENDING
                && $log->attempted_at?->isAfter(now(config('app.timezone'))->subSeconds(self::LOCK_SECONDS))
            ) {
                return null;
            }

            $log->forceFill([
                'customer_id' => $entry->customer_id,
                'repayment_deadline' => $entry->repayment_deadline->toDateString(),
                'outstanding_amount' => $entry->outstanding_balance,
                'delivery_status' => EmailReminderLog::STATUS_SENDING,
                'attempt_count' => $log->attempt_count + 1,
                'attempted_at' => now(config('app.timezone')),
                'sent_at' => null,
                'failure_reason' => null,
            ])->save();

            return $log;
        });
    }

    /**
     * @param  array{ledger_entry_id: int|string, reminder_type: string, scheduled_reminder_date: CarbonImmutable}  $identity
     */
    private function recordSkipped(
        LedgerEntry $entry,
        array $identity,
        string $failureReason,
    ): void {
        DB::transaction(function () use ($entry, $identity, $failureReason): void {
            $log = EmailReminderLog::query()->firstOrCreate($identity, [
                'customer_id' => $entry->customer_id,
                'repayment_deadline' => $entry->repayment_deadline->toDateString(),
                'outstanding_amount' => $entry->outstanding_balance,
                'delivery_status' => EmailReminderLog::STATUS_PENDING,
                'attempt_count' => 0,
            ]);
            $log = EmailReminderLog::query()->lockForUpdate()->findOrFail($log->getKey());

            if ($log->delivery_status === EmailReminderLog::STATUS_SENT) {
                return;
            }

            $log->forceFill([
                'customer_id' => $entry->customer_id,
                'repayment_deadline' => $entry->repayment_deadline->toDateString(),
                'outstanding_amount' => $entry->outstanding_balance,
                'delivery_status' => EmailReminderLog::STATUS_SKIPPED,
                'failure_reason' => $failureReason,
            ])->save();
        });
    }

    private function markFailed(EmailReminderLog $log, string $failureReason): void
    {
        $log->forceFill([
            'delivery_status' => EmailReminderLog::STATUS_FAILED,
            'failure_reason' => $failureReason,
        ])->save();

        Log::warning('Repayment reminder email was not delivered.', [
            'reminder_log_id' => $log->getKey(),
            'ledger_entry_id' => $log->ledger_entry_id,
            'reason' => $failureReason,
        ]);
    }
}
