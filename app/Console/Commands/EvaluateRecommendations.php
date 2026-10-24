<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\RecommendationEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Read-only offline evaluation of single-item association rules against a
 * popularity baseline for one store (DUMS-41). It reads eligible baskets,
 * generates rules in memory, and prints the results; nothing is saved,
 * trained, or activated. See RecommendationEvaluationService for the
 * method and formulas.
 *
 * Exit codes: 0 when the evaluation ran, 1 when it was unavailable for the
 * data, 2 for invalid options.
 */
class EvaluateRecommendations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'apriori:evaluate
        {--store= : Store ID}
        {--from= : First purchase date (Y-m-d); default 90 days before --to}
        {--to= : Last purchase date (Y-m-d); default today}
        {--train-percent=70 : Share of baskets, in date order, used for training (10-90)}';

    /**
     * @var string
     */
    protected $description = 'Offline, time-based evaluation of association-rule suggestions against a popularity baseline (read-only)';

    public function handle(RecommendationEvaluationService $evaluation): int
    {
        $store = ctype_digit((string) $this->option('store')) ? Store::query()->find((int) $this->option('store')) : null;

        if ($store === null) {
            $this->error('--store must be the ID of an existing store.');

            return self::INVALID;
        }

        $timezone = (string) config('app.timezone');

        try {
            $to = $this->option('to') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('to'), $timezone) : CarbonImmutable::now($timezone)->startOfDay();
            $from = $this->option('from') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), $timezone) : $to->subDays(89);
        } catch (\Throwable) {
            $from = $to = false;
        }

        if (! $from || ! $to || $from->greaterThan($to) || ! ctype_digit((string) $this->option('train-percent'))) {
            $this->error('Use dates as Y-m-d with --from on or before --to, and a whole-number --train-percent.');

            return self::INVALID;
        }

        $result = $evaluation->evaluate($store, $from->toDateString(), $to->toDateString(), (int) $this->option('train-percent'));
        $this->line("Store #{$store->id} {$store->store_name} | period {$result['period']['from']} to {$result['period']['to']} | training {$result['method']['train_percent']}%, top {$result['method']['k']}");

        if ($result['status'] !== 'available') {
            foreach ($result['reasons'] as $reason) {
                $this->warn($reason);
            }

            $this->line('Evaluation unavailable. Nothing was changed.');

            return self::FAILURE;
        }

        $split = $result['split'];
        $this->table(['Split', 'Value'], [
            ['training baskets', "{$split['training_baskets']} ({$split['training_period'][0]} to {$split['training_period'][1]})"],
            ['later baskets', "{$split['held_out_baskets']} ({$split['held_out_period'][0]} to {$split['held_out_period'][1]})"],
            ['evaluated (2+ products)', $split['evaluated_baskets']],
            ['rules from training baskets', $result['training_rule_count']],
        ]);
        $this->table(['Measure', 'Association rules', 'Most popular products'], array_map(
            fn (string $metric): array => [$metric, $result['methods']['apriori'][$metric], $result['methods']['popularity'][$metric]],
            ['coverage', 'hit_rate', 'precision', 'recall'],
        ));
        $this->line($result['label'] === 'exploratory'
            ? 'Exploratory: fewer than '.RecommendationEvaluationService::EXPLORATORY_BELOW.' baskets were evaluated.'
            : 'Offline result on recorded sales.');
        $this->line('This compares suggestions with past sales only; it does not show effects on sales or customers. Nothing was changed.');

        return self::SUCCESS;
    }
}
