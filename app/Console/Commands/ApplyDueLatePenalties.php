<?php

namespace App\Console\Commands;

use App\Services\LatePenaltyService;
use Illuminate\Console\Command;

class ApplyDueLatePenalties extends Command
{
    /**
     * @var string
     */
    protected $signature = 'penalties:apply-due';

    /**
     * @var string
     */
    protected $description = 'Add the one-time late payment fee to credits whose grace period has passed. Safe to run repeatedly.';

    public function handle(LatePenaltyService $latePenaltyService): int
    {
        $summary = $latePenaltyService->applyDuePenalties();

        $this->info(sprintf(
            'Checked %d overdue credits: %d fees applied, %d skipped, %d failed.',
            $summary['checked'],
            $summary['applied'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
