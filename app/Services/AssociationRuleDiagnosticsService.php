<?php

namespace App\Services;

use App\Models\AssociationRule;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use App\Support\AssociationMetrics;
use App\Support\AssociationRuleThresholds;

/**
 * Re-checks the rules of a store's active rule set (DUMS-41). Read-only:
 * stored metrics are never changed and nothing is retrained.
 *
 * Each rule is recomputed from the same eligible baskets the model was
 * trained on (purchase IDs up to the rule set's cutoff) with the engine's
 * own formulas, then compared with the stored counts and metrics. Rules
 * are judged against the currently configured engine thresholds.
 *
 * One extra, evaluation-only note is added: FEW_OBSERVATIONS marks rules
 * resting on fewer than FEW_OBSERVATIONS_PAIR_COUNT baskets. It is a
 * reading aid, not a validated threshold, and never changes a category.
 */
class AssociationRuleDiagnosticsService
{
    public const DISPLAY_LIMIT = 50;

    public const FEW_OBSERVATIONS_PAIR_COUNT = 10;

    public const METRICS_MISMATCH = 'metrics_mismatch';

    public const INSUFFICIENT_OBSERVATIONS = 'insufficient_observations';

    public const LOW_SUPPORT = 'low_support';

    public const LOW_CONFIDENCE = 'low_confidence';

    public const WEAK_ASSOCIATION = 'weak_association';

    public const MEETS_THRESHOLDS = 'meets_thresholds';

    /** Category order, most serious first. */
    public const CATEGORIES = [
        self::METRICS_MISMATCH,
        self::INSUFFICIENT_OBSERVATIONS,
        self::LOW_SUPPORT,
        self::LOW_CONFIDENCE,
        self::WEAK_ASSOCIATION,
        self::MEETS_THRESHOLDS,
    ];

    public function __construct(private PurchaseBasketCounter $basketCounter) {}

    /**
     * @return array{
     *     status: string,
     *     reason: string|null,
     *     rule_count: int,
     *     recomputed_basket_count: int|null,
     *     category_counts: array<string, int>,
     *     few_observations_count: int,
     *     rules: list<array{antecedent: string, consequent: string, pair_count: int, antecedent_count: int, consequent_count: int, support: string, confidence: string, lift: string, category: string, few_observations: bool}>
     * }
     */
    public function forStore(Store $store): array
    {
        $storeId = (int) $store->getKey();
        $ruleSet = RuleSet::query()->activeForStore($storeId)->first();
        $empty = fn (string $status, string $reason): array => [
            'status' => $status, 'reason' => $reason, 'rule_count' => 0, 'recomputed_basket_count' => null,
            'category_counts' => [], 'few_observations_count' => 0, 'rules' => [],
        ];

        if ($ruleSet === null) {
            return $empty('no_active_model', 'This store has no active recommendation model, so there are no rules to check.');
        }

        if ($ruleSet->source_cutoff_purchase_id === null) {
            return $empty('unavailable', 'The active model has no recorded purchase cutoff, so its training baskets cannot be identified.');
        }

        $rules = $ruleSet->rules()->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return $empty('no_rules', 'The active model has no rules: no product pair met the thresholds when it was trained.');
        }

        $thresholds = AssociationRuleThresholds::fromConfig();
        $counts = $this->basketCounter->countUpTo($storeId, $ruleSet->source_cutoff_purchase_id, 1);
        $pairCounts = [];

        foreach ($counts->pairCounts as [$first, $second, $pairCount]) {
            $pairCounts["{$first}:{$second}"] = $pairCount;
        }

        $names = Product::query()->where('store_id', $storeId)
            ->whereIn('id', $rules->pluck('antecedent_product_id')->merge($rules->pluck('consequent_product_id'))->unique())
            ->pluck('name', 'id');
        $diagnosed = $rules->map(fn (AssociationRule $rule): array => $this->diagnose($rule, $counts->basketCount, $counts->itemCounts, $pairCounts, $thresholds, (int) $ruleSet->eligible_basket_count, $names->all()));
        $categoryCounts = array_fill_keys(self::CATEGORIES, 0);

        foreach ($diagnosed as $rule) {
            $categoryCounts[$rule['category']]++;
        }

        $order = array_flip(self::CATEGORIES);

        return [
            'status' => 'available',
            'reason' => null,
            'rule_count' => $rules->count(),
            'recomputed_basket_count' => $counts->basketCount,
            'category_counts' => $categoryCounts,
            'few_observations_count' => $diagnosed->where('few_observations', true)->count(),
            'rules' => $diagnosed
                ->sortBy(fn (array $rule): array => [$order[$rule['category']], -$this->units($rule['confidence']), -$this->units($rule['lift']), $rule['antecedent'], $rule['consequent']])
                ->take(self::DISPLAY_LIMIT)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $itemCounts
     * @param  array<string, int>  $pairCounts
     * @param  array<int, string>  $names
     * @return array{antecedent: string, consequent: string, pair_count: int, antecedent_count: int, consequent_count: int, support: string, confidence: string, lift: string, category: string, few_observations: bool}
     */
    private function diagnose(AssociationRule $rule, int $basketCount, array $itemCounts, array $pairCounts, AssociationRuleThresholds $thresholds, int $trainedBasketCount, array $names): array
    {
        $antecedent = $rule->antecedent_product_id;
        $consequent = $rule->consequent_product_id;
        $recomputedPair = $pairCounts[min($antecedent, $consequent).':'.max($antecedent, $consequent)] ?? 0;
        $recomputedAntecedent = $itemCounts[$antecedent] ?? 0;
        $recomputedConsequent = $itemCounts[$consequent] ?? 0;
        $matches = $basketCount === $trainedBasketCount
            && $recomputedPair === $rule->pair_count
            && $recomputedAntecedent === $rule->antecedent_count
            && $recomputedConsequent === $rule->consequent_count
            && $basketCount > 0 && $recomputedPair > 0
            && $rule->support === AssociationMetrics::support($recomputedPair, $basketCount)
            && $rule->confidence === AssociationMetrics::confidence($recomputedPair, $recomputedAntecedent)
            && $rule->lift === AssociationMetrics::lift($recomputedPair, $basketCount, $recomputedAntecedent, $recomputedConsequent);

        $category = match (true) {
            ! $matches => self::METRICS_MISMATCH,
            $rule->pair_count < $thresholds->minPairCount || $rule->antecedent_count < $thresholds->minAntecedentCount => self::INSUFFICIENT_OBSERVATIONS,
            ! AssociationMetrics::ratioMeets($rule->pair_count, $basketCount, $thresholds->minSupportUnits) => self::LOW_SUPPORT,
            ! AssociationMetrics::ratioMeets($rule->pair_count, $rule->antecedent_count, $thresholds->minConfidenceUnits) => self::LOW_CONFIDENCE,
            ! $this->positiveEnough($rule, $basketCount, $thresholds) => self::WEAK_ASSOCIATION,
            default => self::MEETS_THRESHOLDS,
        };

        return [
            'antecedent' => $names[$antecedent] ?? "#{$antecedent}",
            'consequent' => $names[$consequent] ?? "#{$consequent}",
            'pair_count' => $rule->pair_count,
            'antecedent_count' => $rule->antecedent_count,
            'consequent_count' => $rule->consequent_count,
            'support' => $rule->support,
            'confidence' => $rule->confidence,
            'lift' => $rule->lift,
            'category' => $category,
            'few_observations' => $rule->pair_count < self::FEW_OBSERVATIONS_PAIR_COUNT,
        ];
    }

    /**
     * Lift at or above the configured minimum; with the lift filter off, above 1.
     */
    private function positiveEnough(AssociationRule $rule, int $basketCount, AssociationRuleThresholds $thresholds): bool
    {
        if ($thresholds->minLiftUnits !== null) {
            return AssociationMetrics::liftMeets($rule->pair_count, $basketCount, $rule->antecedent_count, $rule->consequent_count, $thresholds->minLiftUnits);
        }

        return $this->units($rule->lift) > AssociationMetrics::UNITS;
    }

    /**
     * A stored six-decimal metric as integer millionths, for exact ordering.
     */
    private function units(string $value): int
    {
        [$whole, $fraction] = explode('.', $value) + [1 => '0'];

        return (int) $whole * AssociationMetrics::UNITS + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }
}
