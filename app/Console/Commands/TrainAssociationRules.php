<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidAssociationThresholds;
use App\Models\Store;
use App\Services\AssociationRuleTrainer;
use App\Support\AssociationRuleThresholds;
use App\Support\TrainingOutcome;
use Illuminate\Console\Command;

/**
 * Trains single-item association rules A → B (DUMS-39). This is pairwise
 * counting, not the full iterative Apriori search for larger itemsets.
 *
 * A scope is always required; stores are never combined into one model:
 * - `--store=ID`     one store's own purchases;
 * - `--all-stores`   every active store, each trained separately;
 * - `--source=external --dataset=PATH`   a global external experiment,
 *   never activated and never shown to store owners.
 *
 * Exit codes: 0 when every scope finished (activated, validated, recorded
 * as insufficient data, or dry run); 1 when any scope failed or was
 * locked by another run; 2 for invalid options.
 */
class TrainAssociationRules extends Command
{
    /**
     * @var string
     */
    protected $signature = 'apriori:train
        {--store= : Train one store, by store ID}
        {--all-stores : Train every active store separately}
        {--source=internal : internal (store purchases) or external (an external basket CSV)}
        {--dataset= : Path to the external basket CSV, with --source=external}
        {--dry-run : Calculate the full metrics and report them without saving or activating anything}
        {--min-support= : Minimum support, 0 to 1}
        {--min-confidence= : Minimum confidence, 0 to 1}
        {--min-lift= : Minimum lift, 0 to 1000, or "off"}
        {--min-baskets= : Minimum eligible baskets for the scope}
        {--min-antecedent= : Minimum baskets containing the antecedent product}
        {--min-pair= : Minimum baskets containing both products}';

    /**
     * @var string
     */
    protected $description = 'Train single-item product association rules (A → B) for a store, every store separately, or an external experiment';

    public function handle(AssociationRuleTrainer $trainer): int
    {
        try {
            $thresholds = AssociationRuleThresholds::fromConfig([
                'min_support' => $this->option('min-support'),
                'min_confidence' => $this->option('min-confidence'),
                'min_lift' => $this->option('min-lift'),
                'min_baskets' => $this->option('min-baskets'),
                'min_antecedent_count' => $this->option('min-antecedent'),
                'min_pair_count' => $this->option('min-pair'),
            ]);
        } catch (InvalidAssociationThresholds $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $source = (string) $this->option('source');
        $storeOption = $this->option('store');
        $allStores = (bool) $this->option('all-stores');
        $dataset = $this->option('dataset');

        if ($source === 'external') {
            if ($storeOption !== null || $allStores || blank($dataset)) {
                return $this->usageError('An external experiment needs --dataset=PATH and cannot be combined with --store or --all-stores.');
            }

            $this->warn('External experiment: the rules describe the external dataset only, are never activated, and are not Philippine sari-sari buying patterns.');

            return $this->report([$trainer->trainExternal((string) $dataset, $thresholds, $dryRun)], $dryRun);
        }

        if ($source !== 'internal') {
            return $this->usageError('--source must be internal or external.');
        }

        if (filled($dataset)) {
            return $this->usageError('--dataset is only used with --source=external.');
        }

        if (($storeOption !== null) === $allStores) {
            return $this->usageError('Choose exactly one scope: --store=ID or --all-stores. Stores are never combined into one model.');
        }

        if ($allStores) {
            $outcomes = Store::query()
                ->where('status', Store::STATUS_ACTIVE)
                ->orderBy('id')
                ->get()
                ->map(fn (Store $store): TrainingOutcome => $trainer->trainStore($store, $thresholds, $dryRun))
                ->all();

            if ($outcomes === []) {
                $this->warn('There are no active stores to train.');
            }

            return $this->report($outcomes, $dryRun);
        }

        if (! ctype_digit((string) $storeOption)) {
            return $this->usageError('--store must be a store ID.');
        }

        $store = Store::query()->find((int) $storeOption);

        if ($store === null) {
            return $this->usageError("Store {$storeOption} does not exist.");
        }

        return $this->report([$trainer->trainStore($store, $thresholds, $dryRun)], $dryRun);
    }

    /**
     * @param  list<TrainingOutcome>  $outcomes
     */
    private function report(array $outcomes, bool $dryRun): int
    {
        foreach ($outcomes as $outcome) {
            $this->newLine();
            $this->line("<info>Scope:</info> {$outcome->scopeKey}");
            $this->table(['Result', 'Value'], [
                ['status', $outcome->status],
                ['rule set', $outcome->ruleSet?->getKey() ?? '—'],
                ['eligible baskets', $outcome->basketCount ?? '—'],
                ['candidate pairs', $outcome->candidatePairCount ?? '—'],
                ['rules', $outcome->ruleCount ?? '—'],
                ['duration (ms)', $outcome->durationMs ?? '—'],
            ]);

            if ($outcome->reason !== null) {
                $outcome->succeeded() ? $this->warn($outcome->reason) : $this->error($outcome->reason);
            }

            if ($outcome->previewRules !== []) {
                $this->table(
                    ['If bought', 'Also bought', 'Both', 'Support', 'Confidence', 'Lift'],
                    array_map(fn (array $rule): array => [
                        $rule['antecedent'], $rule['consequent'], $rule['pair_count'], $rule['support'], $rule['confidence'], $rule['lift'],
                    ], $outcome->previewRules),
                );
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Dry run: metrics were calculated, but nothing was saved or activated.');
        }

        $failed = array_filter($outcomes, fn (TrainingOutcome $outcome): bool => ! $outcome->succeeded());

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function usageError(string $message): int
    {
        $this->error($message);
        $this->line('Usage: php artisan apriori:train --store=ID | --all-stores | --source=external --dataset=PATH [--dry-run]');

        return self::INVALID;
    }
}
