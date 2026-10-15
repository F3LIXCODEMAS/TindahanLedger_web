<?php

namespace App\Console\Commands;

use App\Services\PaymentReminderService;
use Illuminate\Console\Command;

class SendDueReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled repayment reminders and retry failed deliveries';

    public function handle(PaymentReminderService $paymentReminderService): int
    {
        $summary = $paymentReminderService->sendDueReminders();

        $this->info(sprintf(
            'Processed: %d | Sent: %d | Skipped: %d | Failed: %d',
            $summary['processed'],
            $summary['sent'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
