<?php

use App\Mail\ReusableEmail;
use App\Models\Customer;
use App\Models\EmailReminderLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StoreEmailSetting;
use App\Models\User;
use App\Services\MailDeliveryService;
use App\Services\PaymentReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  list<bool>  $deliveryResults
 */
function captureReminderDeliveries(array $deliveryResults): Collection
{
    $captured = collect();
    $deliveryService = Mockery::mock(MailDeliveryService::class)->makePartial();

    $deliveryService->shouldReceive('sendAsOwner')
        ->times(count($deliveryResults))
        ->andReturnUsing(function (
            User $owner,
            Mailable $mailable,
            ?string $recipient,
            string $notificationType,
        ) use ($captured, &$deliveryResults): bool {
            $captured->push([
                'owner' => $owner,
                'mailable' => $mailable,
                'recipient' => $recipient,
                'notification_type' => $notificationType,
            ]);

            return array_shift($deliveryResults);
        });

    app()->instance(MailDeliveryService::class, $deliveryService);

    return $captured;
}

function createReminderEntry(?string $email, string $deadline, string $amount = '300.00'): array
{
    $customer = Customer::factory()->create([
        'email' => $email,
        'credit_limit' => '5000.00',
    ]);
    $entry = LedgerEntry::factory()->for($customer)->create([
        'amount' => $amount,
        'running_balance' => $amount,
        'repayment_deadline' => $deadline,
    ]);

    return [$customer, $entry];
}

it('sends a reminder with the outstanding balance after partial payments', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    [$customer, $entry] = createReminderEntry('customer@example.test', '2026-10-20');
    Payment::factory()->for($customer)->for($entry)->create(['amount' => '50.00']);
    $captured = captureReminderDeliveries([true]);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary)->toBe(['processed' => 1, 'sent' => 1, 'skipped' => 0, 'failed' => 0]);
    expect($captured)->toHaveCount(1);
    expect($captured->first()['recipient'])->toBe('customer@example.test');
    expect($captured->first()['notification_type'])->toBe('repayment_reminder');
    expect($captured->first()['mailable'])->toBeInstanceOf(ReusableEmail::class);
    expect($captured->first()['mailable']->greeting)->toBe('Hello '.$customer->full_name.',');
    expect($captured->first()['mailable']->envelope()->subject)->toBe('TindahanLedger – Upcoming payment reminder');
    expect($captured->first()['mailable']->render())
        ->toContain('₱250.00')
        ->toContain('October 20, 2026');

    $this->assertDatabaseHas('email_reminder_logs', [
        'customer_id' => $customer->id,
        'ledger_entry_id' => $entry->id,
        'reminder_type' => EmailReminderLog::TYPE_ADVANCE,
        'outstanding_amount' => '250.00',
        'delivery_status' => EmailReminderLog::STATUS_SENT,
        'attempt_count' => 1,
    ]);
    expect(EmailReminderLog::query()->sole()->scheduled_reminder_date->toDateString())->toBe('2026-10-17');
});

it('does not create reminders for fully paid ledger entries', function () {
    config(['app.timezone' => 'Asia/Manila']);
    [$customer, $entry] = createReminderEntry('customer@example.test', '2026-10-20');
    Payment::factory()->for($customer)->for($entry)->create(['amount' => '300.00']);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary)->toBe(['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0]);
    $this->assertDatabaseCount('email_reminder_logs', 0);
});

it('records missing and invalid customer email addresses as skipped', function () {
    config(['app.timezone' => 'Asia/Manila']);
    createReminderEntry(null, '2026-10-20');
    createReminderEntry('customer@example.invalid', '2026-10-20');
    $deliveryService = Mockery::mock(MailDeliveryService::class)->makePartial();
    $deliveryService->shouldNotReceive('sendAsOwner');
    app()->instance(MailDeliveryService::class, $deliveryService);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary)->toBe(['processed' => 2, 'sent' => 0, 'skipped' => 2, 'failed' => 0]);
    $this->assertDatabaseHas('email_reminder_logs', [
        'delivery_status' => EmailReminderLog::STATUS_SKIPPED,
        'failure_reason' => 'customer_email_missing',
    ]);
    $this->assertDatabaseHas('email_reminder_logs', [
        'delivery_status' => EmailReminderLog::STATUS_SKIPPED,
        'failure_reason' => 'customer_email_invalid',
    ]);
});

it('records sender configuration problems without selecting an arbitrary store account', function (
    int $settingsCount,
    string $expectedReason,
) {
    config(['app.timezone' => 'Asia/Manila']);
    for ($index = 0; $index < $settingsCount; $index++) {
        StoreEmailSetting::factory()->for(User::factory()->create())->create();
    }
    createReminderEntry('customer@example.test', '2026-10-20');
    $deliveryService = Mockery::mock(MailDeliveryService::class)->makePartial();
    $deliveryService->shouldNotReceive('sendAsOwner');
    app()->instance(MailDeliveryService::class, $deliveryService);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary['failed'])->toBe(1);
    $this->assertDatabaseHas('email_reminder_logs', [
        'delivery_status' => EmailReminderLog::STATUS_FAILED,
        'failure_reason' => $expectedReason,
    ]);
})->with([
    'sender not configured' => [0, 'sender_not_configured'],
    'multiple accounts' => [2, 'multiple_store_email_settings'],
]);

it('retries failed reminders and never resends a successful trigger', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    [$customer] = createReminderEntry('customer@example.test', '2026-10-20');
    $captured = captureReminderDeliveries([false, true]);
    $service = app(PaymentReminderService::class);
    $today = CarbonImmutable::parse('2026-10-17', 'Asia/Manila');

    $firstRun = $service->sendDueReminders($today);
    $retryRun = $service->sendDueReminders($today);
    $successfulDuplicateRun = $service->sendDueReminders($today);

    expect($firstRun)->toBe(['processed' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 1]);
    expect($retryRun)->toBe(['processed' => 1, 'sent' => 1, 'skipped' => 0, 'failed' => 0]);
    expect($successfulDuplicateRun)->toBe(['processed' => 1, 'sent' => 0, 'skipped' => 1, 'failed' => 0]);
    expect($captured)->toHaveCount(2);

    $log = EmailReminderLog::query()->sole();
    expect($log->customer_id)->toBe($customer->id);
    expect($log->delivery_status)->toBe(EmailReminderLog::STATUS_SENT);
    expect($log->failure_reason)->toBeNull();
    expect($log->attempt_count)->toBe(2);
    expect($log->sent_at)->not->toBeNull();
});

it('recovers a reminder left in sending status after a process interruption', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 08:05:00', 'Asia/Manila'));
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    [$customer, $entry] = createReminderEntry('customer@example.test', '2026-10-20');
    EmailReminderLog::factory()->forLedgerEntry($entry)->create([
        'reminder_type' => EmailReminderLog::TYPE_ADVANCE,
        'scheduled_reminder_date' => '2026-10-17',
        'delivery_status' => EmailReminderLog::STATUS_SENDING,
        'attempt_count' => 1,
        'attempted_at' => CarbonImmutable::parse('2026-10-16 00:00:00', 'Asia/Manila'),
        'sent_at' => null,
        'failure_reason' => null,
    ]);
    $captured = captureReminderDeliveries([true]);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary)->toBe(['processed' => 1, 'sent' => 1, 'skipped' => 0, 'failed' => 0]);
    expect($captured)->toHaveCount(1);
    $log = EmailReminderLog::query()->sole();
    expect($log->customer_id)->toBe($customer->id);
    expect($log->delivery_status)->toBe(EmailReminderLog::STATUS_SENT);
    expect($log->attempt_count)->toBe(2);
});

it('does not process a reminder while its previous send attempt is still active', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 08:02:00', 'Asia/Manila'));
    [$customer, $entry] = createReminderEntry('customer@example.test', '2026-10-20');
    EmailReminderLog::factory()->forLedgerEntry($entry)->create([
        'reminder_type' => EmailReminderLog::TYPE_ADVANCE,
        'scheduled_reminder_date' => '2026-10-17',
        'delivery_status' => EmailReminderLog::STATUS_SENDING,
        'attempt_count' => 1,
        'attempted_at' => CarbonImmutable::parse('2026-10-17 08:00:00', 'Asia/Manila'),
        'sent_at' => null,
        'failure_reason' => null,
    ]);
    $deliveryService = Mockery::mock(MailDeliveryService::class)->makePartial();
    $deliveryService->shouldNotReceive('sendAsOwner');
    app()->instance(MailDeliveryService::class, $deliveryService);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary['skipped'])->toBe(1);
    $log = EmailReminderLog::query()->sole();
    expect($log->customer_id)->toBe($customer->id);
    expect($log->delivery_status)->toBe(EmailReminderLog::STATUS_SENDING);
    expect($log->attempt_count)->toBe(1);
});

it('continues processing other reminders after one delivery fails', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    createReminderEntry('first@example.test', '2026-10-20');
    createReminderEntry('second@example.test', '2026-10-20');
    $captured = captureReminderDeliveries([false, true]);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
    );

    expect($summary)->toBe(['processed' => 2, 'sent' => 1, 'skipped' => 0, 'failed' => 1]);
    expect($captured)->toHaveCount(2);
    $this->assertDatabaseHas('email_reminder_logs', ['delivery_status' => EmailReminderLog::STATUS_FAILED]);
    $this->assertDatabaseHas('email_reminder_logs', ['delivery_status' => EmailReminderLog::STATUS_SENT]);
});

it('uses the configured timezone when determining the scheduled calendar date', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    createReminderEntry('customer@example.test', '2026-10-21');
    captureReminderDeliveries([true]);

    $summary = app(PaymentReminderService::class)->sendDueReminders(
        CarbonImmutable::parse('2026-10-17 16:30:00', 'UTC'),
    );

    expect($summary['sent'])->toBe(1);
    $log = EmailReminderLog::query()->sole();
    expect($log->scheduled_reminder_date->toDateString())->toBe('2026-10-18');
    expect($log->reminder_type)->toBe(EmailReminderLog::TYPE_ADVANCE);
});

it('registers the daily repayment reminder scheduler entry', function () {
    $exitCode = Artisan::call('schedule:list');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('reminders:send-due');
});

it('reports processing totals from the repayment reminder command', function () {
    $exitCode = Artisan::call('reminders:send-due');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Processed: 0 | Sent: 0 | Skipped: 0 | Failed: 0');
});
