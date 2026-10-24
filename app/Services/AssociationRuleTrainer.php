<?php

namespace App\Services;

use App\Exceptions\RuleSetValidationFailed;
use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use App\Support\AssociationMetrics;
use App\Support\AssociationRuleThresholds;
use App\Support\BasketCounts;
use App\Support\TrainingOutcome;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trains, validates, and publishes single-item association rules (DUMS-39).
 *
 * Lifecycle of one run, under a lock for its scope:
 * 1. Mark abandoned `building` runs of the scope as failed.
 * 2. Create a `building` rule set; the active version is untouched.
 * 3. Count baskets from one consistent snapshot. Below the minimum basket
 *    count the run is recorded as `insufficient_data` and nothing is
 *    published.
 * 4. Generate and save the rules, then re-check every saved rule against
 *    the counts and thresholds. The run becomes `validated`.
 * 5. Store runs are activated atomically (RuleSetActivator), superseding
 *    the previous version. External experiments stop at `validated`.
 * Any failure marks the run `failed` and leaves the active version as it
 * was. A run leaves `building` only while its status is still `building`:
 * if it outlived its lock and a later run marked it abandoned, it fails
 * instead of being validated or activated.
 *
 * Training only reads purchases and products. It never writes customers,
 * ledger entries, payments, purchases, Suki Points, or penalties.
 */
class AssociationRuleTrainer
{
    private const INSERT_CHUNK = 500;

    private const PREVIEW_RULES = 10;

    public function __construct(
        private PurchaseBasketCounter $basketCounter,
        private AssociationRuleGenerator $generator,
        private RuleSetActivator $activator,
        private BasketDatasetValidator $datasetValidator,
    ) {}

    /**
     * Train one store's model from its own purchases only.
     */
    public function trainStore(Store $store, AssociationRuleThresholds $thresholds, bool $dryRun = false): TrainingOutcome
    {
        $storeId = (int) $store->getKey();
        $scopeKey = RuleSet::storeScopeKey($storeId);
        $describeItems = fn (array $productIds): array => Product::query()
            ->where('store_id', $storeId)
            ->whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->all();

        if ($dryRun) {
            return $this->dryRun($scopeKey, $this->basketCounter->count($storeId, $thresholds->minPairCount)['counts'], $thresholds, $describeItems);
        }

        return $this->withScopeLock($scopeKey, fn (): TrainingOutcome => $this->build(
            [
                'scope_type' => RuleSet::SCOPE_STORE,
                'store_id' => $storeId,
                'scope_key' => $scopeKey,
                'source_type' => RuleSet::SOURCE_INTERNAL_PURCHASES,
                'source_identifier' => null,
                'source_details' => null,
            ],
            function () use ($storeId, $thresholds): array {
                $snapshot = $this->basketCounter->count($storeId, $thresholds->minPairCount);

                return [$snapshot['counts'], ['source_cutoff_purchase_id' => $snapshot['cutoff_purchase_id']]];
            },
            $thresholds,
            fn (array $rule): array => [
                'store_id' => $storeId,
                'antecedent_product_id' => $rule['antecedent'],
                'consequent_product_id' => $rule['consequent'],
                'antecedent_label' => null,
                'consequent_label' => null,
            ],
            fn (AssociationRule $rule): array => [$rule->antecedent_product_id, $rule->consequent_product_id],
            activate: true,
        ));
    }

    /**
     * Train a global external experiment from a validated basket CSV. It is
     * kept apart from every store: no store, no catalog products, never
     * activated, and never read by the recommendation service.
     */
    public function trainExternal(string $path, AssociationRuleThresholds $thresholds, bool $dryRun = false): TrainingOutcome
    {
        $validation = $this->datasetValidator->validateWithBaskets($path);
        $report = $validation['report'];

        if ($report['file_errors'] !== []) {
            return new TrainingOutcome(RuleSet::SCOPE_EXTERNAL_EXPERIMENT, RuleSet::STATUS_FAILED, reason: implode(' ', $report['file_errors']));
        }

        $labels = array_values(array_unique(array_merge([], ...$validation['baskets'])));
        sort($labels, SORT_STRING);
        $indexes = array_flip($labels);
        $baskets = array_map(fn (array $basket): array => array_map(fn (string $label): int => $indexes[$label], $basket), $validation['baskets']);
        $scopeKey = RuleSet::externalScopeKey($report['file']['sha256']);
        $countBaskets = fn (): array => [BasketCounts::fromBaskets($baskets, $thresholds->minPairCount), []];
        $describeItems = fn (array $itemIndexes): array => array_combine($itemIndexes, array_map(fn (int $index): string => $labels[$index], $itemIndexes));

        if ($dryRun) {
            return $this->dryRun($scopeKey, $countBaskets()[0], $thresholds, $describeItems);
        }

        return $this->withScopeLock($scopeKey, fn (): TrainingOutcome => $this->build(
            [
                'scope_type' => RuleSet::SCOPE_EXTERNAL_EXPERIMENT,
                'store_id' => null,
                'scope_key' => $scopeKey,
                'source_type' => RuleSet::SOURCE_EXTERNAL_DATASET,
                'source_identifier' => $report['file']['sha256'],
                'source_details' => [
                    'file_name' => basename($path),
                    'bytes' => $report['file']['bytes'],
                    'rows_read' => $report['rows']['read'],
                    'rows_accepted' => $report['rows']['accepted'],
                    'rows_rejected' => $report['rows']['rejected'],
                    'notice' => 'External experiment only. Not verified Philippine sari-sari buying patterns; never activated or shown to store owners.',
                ],
            ],
            $countBaskets,
            $thresholds,
            fn (array $rule): array => [
                'store_id' => null,
                'antecedent_product_id' => null,
                'consequent_product_id' => null,
                'antecedent_label' => $labels[$rule['antecedent']],
                'consequent_label' => $labels[$rule['consequent']],
            ],
            fn (AssociationRule $rule): array => [$indexes[$rule->antecedent_label] ?? null, $indexes[$rule->consequent_label] ?? null],
            activate: false,
        ));
    }

    private function withScopeLock(string $scopeKey, Closure $train): TrainingOutcome
    {
        $lock = Cache::lock('association-rules:train:'.$scopeKey, (int) config('association_rules.lock_seconds'));

        if (! $lock->get()) {
            Log::warning('Association rule training skipped: another run holds the lock.', ['scope_key' => $scopeKey]);

            return new TrainingOutcome($scopeKey, TrainingOutcome::LOCKED, reason: 'Another training run for this scope is in progress.');
        }

        try {
            return $train();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(): array{0: BasketCounts, 1: array<string, mixed>}  $countBaskets  the counts, plus rule set attributes describing their snapshot
     * @param  Closure(array<string, mixed>): array<string, mixed>  $itemColumns
     * @param  Closure(AssociationRule): array{0: int|null, 1: int|null}  $itemKeys
     */
    private function build(array $attributes, Closure $countBaskets, AssociationRuleThresholds $thresholds, Closure $itemColumns, Closure $itemKeys, bool $activate): TrainingOutcome
    {
        $startedAt = hrtime(true);
        $this->failAbandonedRuns($attributes['scope_key']);

        $ruleSet = new RuleSet;
        $ruleSet->forceFill([
            ...$attributes,
            'algorithm' => RuleSet::ALGORITHM,
            'algorithm_version' => RuleSet::ALGORITHM_VERSION,
            'parameters' => $thresholds->toArray(),
            'status' => RuleSet::STATUS_BUILDING,
            'started_at' => now(),
        ])->save();

        try {
            [$counts, $snapshotAttributes] = $countBaskets();
            $ruleSet->forceFill([
                ...$snapshotAttributes,
                'eligible_basket_count' => $counts->basketCount,
                'distinct_item_count' => count($counts->itemCounts),
                'candidate_pair_count' => count($counts->pairCounts),
            ]);

            if ($counts->basketCount > AssociationMetrics::MAX_BASKETS) {
                throw new RuleSetValidationFailed('The scope has more than '.AssociationMetrics::MAX_BASKETS.' eligible baskets, which this version does not support.');
            }

            if ($counts->basketCount < $thresholds->minBaskets) {
                $this->leaveBuilding($ruleSet, RuleSet::STATUS_INSUFFICIENT_DATA);

                return $this->finish($ruleSet, RuleSet::STATUS_INSUFFICIENT_DATA, $startedAt, sprintf(
                    'Only %d eligible baskets; at least %d are required. No rules were published.',
                    $counts->basketCount,
                    $thresholds->minBaskets,
                ));
            }

            $rules = $this->generator->generate($counts, $thresholds);
            $this->saveRules($ruleSet, $rules, $itemColumns);
            $ruleSet->forceFill(['rule_count' => count($rules)]);
            $this->validateSavedRules($ruleSet, $counts, $thresholds, count($rules), $itemKeys);
            $this->leaveBuilding($ruleSet, RuleSet::STATUS_VALIDATED);
        } catch (Throwable $exception) {
            return $this->fail($ruleSet, $startedAt, $exception, $exception instanceof RuleSetValidationFailed
                ? $exception->getMessage()
                : 'Training failed ('.class_basename($exception).'); details are in the application log.');
        }

        if (! $activate) {
            return $this->finish($ruleSet, RuleSet::STATUS_VALIDATED, $startedAt);
        }

        try {
            $ruleSet = $this->activator->activate($ruleSet);
        } catch (Throwable $exception) {
            return $this->fail($ruleSet, $startedAt, $exception, 'Activation failed ('.class_basename($exception).'); the previously active rule set is unchanged.');
        }

        return $this->finish($ruleSet, RuleSet::STATUS_ACTIVE, $startedAt);
    }

    /**
     * Save the run's pending changes and its new status, but only while it
     * is still `building`. A run that outlived its lock may meanwhile have
     * been marked abandoned by a later run; it must not overwrite that.
     */
    private function leaveBuilding(RuleSet $ruleSet, string $status): void
    {
        $updated = RuleSet::query()
            ->whereKey($ruleSet->getKey())
            ->where('status', RuleSet::STATUS_BUILDING)
            ->update([...$ruleSet->getDirty(), 'status' => $status]);

        if ($updated !== 1) {
            throw new RuleSetValidationFailed("Rule set {$ruleSet->getKey()} is no longer building (a later run marked it abandoned), so it was not marked {$status}.");
        }

        $ruleSet->forceFill(['status' => $status])->syncOriginal();
    }

    /**
     * Holding the scope lock means no other run of this scope is alive, so
     * any rule set still `building` was left behind by a crashed run or by a
     * run that outlived its lock.
     */
    private function failAbandonedRuns(string $scopeKey): void
    {
        RuleSet::query()
            ->where('scope_key', $scopeKey)
            ->where('status', RuleSet::STATUS_BUILDING)
            ->update([
                'status' => RuleSet::STATUS_FAILED,
                'failure_reason' => 'Abandoned: the training run stopped before finishing.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function saveRules(RuleSet $ruleSet, array $rules, Closure $itemColumns): void
    {
        $createdAt = now();

        DB::transaction(function () use ($ruleSet, $rules, $itemColumns, $createdAt): void {
            foreach (array_chunk($rules, self::INSERT_CHUNK) as $chunk) {
                AssociationRule::query()->insert(array_map(fn (array $rule): array => [
                    'rule_set_id' => $ruleSet->getKey(),
                    ...$itemColumns($rule),
                    'antecedent_count' => $rule['antecedent_count'],
                    'consequent_count' => $rule['consequent_count'],
                    'pair_count' => $rule['pair_count'],
                    'support' => $rule['support'],
                    'confidence' => $rule['confidence'],
                    'lift' => $rule['lift'],
                    'created_at' => $createdAt,
                ], $chunk));
            }
        });
    }

    /**
     * Re-read every saved rule and check it against the basket counts:
     * scope, no self-rule, counts, recomputed metrics, and thresholds.
     */
    private function validateSavedRules(RuleSet $ruleSet, BasketCounts $counts, AssociationRuleThresholds $thresholds, int $expectedCount, Closure $itemKeys): void
    {
        $savedCount = $ruleSet->rules()->count();

        if ($savedCount !== $expectedCount) {
            throw new RuleSetValidationFailed("Rule set {$ruleSet->getKey()} saved {$savedCount} rules but generated {$expectedCount}.");
        }

        $isStore = $ruleSet->scope_type === RuleSet::SCOPE_STORE;
        $pairCounts = [];

        foreach ($counts->pairCounts as [$first, $second, $pairCount]) {
            $pairCounts[$first.':'.$second] = $pairCount;
        }

        foreach ($ruleSet->rules()->orderBy('id')->lazy(self::INSERT_CHUNK) as $rule) {
            [$antecedent, $consequent] = $itemKeys($rule);
            $where = "Rule {$rule->getKey()} of rule set {$ruleSet->getKey()}";
            $inScope = $isStore
                ? $rule->store_id === $ruleSet->store_id && $rule->antecedent_label === null && $rule->consequent_label === null
                : $rule->store_id === null && $rule->antecedent_product_id === null && $rule->consequent_product_id === null;

            if (! $inScope || $antecedent === null || $consequent === null) {
                throw new RuleSetValidationFailed("{$where} is outside the rule set's scope.");
            }

            if ($antecedent === $consequent) {
                throw new RuleSetValidationFailed("{$where} is a self-rule.");
            }

            $pairKey = min($antecedent, $consequent).':'.max($antecedent, $consequent);

            if ($rule->antecedent_count !== ($counts->itemCounts[$antecedent] ?? null)
                || $rule->consequent_count !== ($counts->itemCounts[$consequent] ?? null)
                || $rule->pair_count !== ($pairCounts[$pairKey] ?? null)) {
                throw new RuleSetValidationFailed("{$where} has counts that differ from the basket counts.");
            }

            $basketCount = $counts->basketCount;

            if ($rule->support !== AssociationMetrics::support($rule->pair_count, $basketCount)
                || $rule->confidence !== AssociationMetrics::confidence($rule->pair_count, $rule->antecedent_count)
                || $rule->lift !== AssociationMetrics::lift($rule->pair_count, $basketCount, $rule->antecedent_count, $rule->consequent_count)) {
                throw new RuleSetValidationFailed("{$where} has metrics that do not match its counts.");
            }

            if ($rule->pair_count < $thresholds->minPairCount
                || $rule->antecedent_count < $thresholds->minAntecedentCount
                || ! AssociationMetrics::ratioMeets($rule->pair_count, $basketCount, $thresholds->minSupportUnits)
                || ! AssociationMetrics::ratioMeets($rule->pair_count, $rule->antecedent_count, $thresholds->minConfidenceUnits)
                || ($thresholds->minLiftUnits !== null && ! AssociationMetrics::liftMeets($rule->pair_count, $basketCount, $rule->antecedent_count, $rule->consequent_count, $thresholds->minLiftUnits))) {
                throw new RuleSetValidationFailed("{$where} does not meet the thresholds.");
            }
        }
    }

    /**
     * Calculate full metrics without saving anything.
     *
     * @param  Closure(list<int>): array<int, string>  $describeItems
     */
    private function dryRun(string $scopeKey, BasketCounts $counts, AssociationRuleThresholds $thresholds, Closure $describeItems): TrainingOutcome
    {
        $startedAt = hrtime(true);

        if ($counts->basketCount < $thresholds->minBaskets) {
            return new TrainingOutcome($scopeKey, TrainingOutcome::DRY_RUN, basketCount: $counts->basketCount,
                candidatePairCount: count($counts->pairCounts), ruleCount: 0, durationMs: $this->elapsedMs($startedAt),
                reason: sprintf('Insufficient data: only %d eligible baskets; at least %d are required.', $counts->basketCount, $thresholds->minBaskets));
        }

        $rules = $this->generator->generate($counts, $thresholds);
        usort($rules, fn (array $left, array $right): int => [(float) $right['confidence'], (float) $right['lift'], $right['pair_count'], $left['antecedent'], $left['consequent']]
            <=> [(float) $left['confidence'], (float) $left['lift'], $left['pair_count'], $right['antecedent'], $right['consequent']]);
        $preview = array_slice($rules, 0, self::PREVIEW_RULES);
        $names = $describeItems(array_values(array_unique(array_merge(array_column($preview, 'antecedent'), array_column($preview, 'consequent')))));

        return new TrainingOutcome($scopeKey, TrainingOutcome::DRY_RUN, basketCount: $counts->basketCount,
            candidatePairCount: count($counts->pairCounts), ruleCount: count($rules), durationMs: $this->elapsedMs($startedAt),
            previewRules: array_map(fn (array $rule): array => [
                ...$rule,
                'antecedent' => (string) ($names[$rule['antecedent']] ?? $rule['antecedent']),
                'consequent' => (string) ($names[$rule['consequent']] ?? $rule['consequent']),
            ], $preview));
    }

    private function finish(RuleSet $ruleSet, string $status, int $startedAt, ?string $reason = null): TrainingOutcome
    {
        $ruleSet->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
            'completed_at' => now(),
            'duration_ms' => $this->elapsedMs($startedAt),
        ])->save();

        $this->logOutcome('Association rule training finished.', $ruleSet);

        return $this->outcome($ruleSet);
    }

    private function fail(RuleSet $ruleSet, int $startedAt, Throwable $exception, string $reason): TrainingOutcome
    {
        $ruleSet->forceFill([
            'status' => RuleSet::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 255),
            'completed_at' => now(),
            'duration_ms' => $this->elapsedMs($startedAt),
        ])->save();

        $this->logOutcome('Association rule training failed.', $ruleSet, $exception);

        return $this->outcome($ruleSet);
    }

    private function outcome(RuleSet $ruleSet): TrainingOutcome
    {
        return new TrainingOutcome(
            scopeKey: $ruleSet->scope_key,
            status: $ruleSet->status,
            ruleSet: $ruleSet,
            basketCount: $ruleSet->eligible_basket_count,
            candidatePairCount: $ruleSet->candidate_pair_count,
            ruleCount: $ruleSet->rule_count,
            reason: $ruleSet->failure_reason,
            durationMs: $ruleSet->duration_ms,
        );
    }

    /**
     * Identifiers and counts only; never customer details.
     */
    private function logOutcome(string $message, RuleSet $ruleSet, ?Throwable $exception = null): void
    {
        $context = [
            'scope_key' => $ruleSet->scope_key,
            'rule_set_id' => $ruleSet->getKey(),
            'status' => $ruleSet->status,
            'eligible_baskets' => $ruleSet->eligible_basket_count,
            'candidate_pairs' => $ruleSet->candidate_pair_count,
            'rules' => $ruleSet->rule_count,
            'duration_ms' => $ruleSet->duration_ms,
            'reason' => $ruleSet->failure_reason,
        ];

        if ($exception === null) {
            Log::info($message, $context);

            return;
        }

        Log::error($message, [...$context, 'exception' => $exception]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
