<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Support\AssociationMetrics;
use App\Support\AssociationRuleThresholds;
use App\Support\BasketCounts;
use Illuminate\Support\Facades\DB;

/**
 * Offline, time-based evaluation of single-item association rules against
 * a popularity baseline (DUMS-41). Read-only: rules are generated in memory
 * with the engine's own generator and thresholds; nothing is saved,
 * trained, or activated, and the live model is not used.
 *
 * Method
 * 1. Eligible baskets in the period (PurchaseBasketCounter rules) are
 *    sorted by purchase date, then purchase ID.
 * 2. Training = the earliest `train_percent`% of baskets, extended to the
 *    end of the boundary day so no day is split. Held-out = later days.
 * 3. Rules come from training baskets only; the baseline ranks products by
 *    how many training baskets contain them (ties: lower product ID).
 * 4. Each held-out basket with at least two distinct products is
 *    evaluated: its last-entered product (highest purchase-item ID) is
 *    hidden, and the others are the input, as when an owner is part-way
 *    through entering a sale.
 * 5. Each method suggests up to K products, never input products or
 *    products now inactive (inactive input products are not used as rule
 *    sources, as in the live picker). Apriori ranks like the picker: the
 *    strongest rule from any input product per candidate (confidence,
 *    lift, co-occurrence, lower antecedent ID), then lower product ID.
 *
 * Metrics, over the same evaluated baskets for both methods (n):
 * - Coverage = baskets with at least one suggestion / n
 * - Hit rate@K = baskets whose hidden product is among the suggestions / n
 *   (a basket without suggestions is a miss)
 * - Precision@K = hits / (K × n)
 * - Recall@K = hits / n; with one hidden product it equals the hit rate
 *
 * Results below EXPLORATORY_BELOW evaluated baskets are labelled
 * exploratory. Offline results describe recorded data only; they do not
 * show effects on sales, profit, or customer behaviour.
 */
class RecommendationEvaluationService
{
    public const DEFAULT_TRAIN_PERCENT = 70;

    public const K = ProductRecommendationService::BASKET_LIMIT;

    public const MIN_EVALUATED_BASKETS = 20;

    public const EXPLORATORY_BELOW = 100;

    public const MAX_BASKETS = 20000;

    public function __construct(
        private PurchaseBasketCounter $basketCounter,
        private AssociationRuleGenerator $generator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Store $store, string $fromDate, string $toDate, int $trainPercent = self::DEFAULT_TRAIN_PERCENT): array
    {
        $storeId = (int) $store->getKey();
        $thresholds = AssociationRuleThresholds::fromConfig();
        $result = [
            'status' => 'unavailable',
            'reasons' => [],
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'method' => [
                'split' => 'chronological',
                'train_percent' => $trainPercent,
                'k' => self::K,
                'hidden_product' => 'last entered product of each held-out basket with two or more products',
                'baseline' => 'most frequent products in training baskets',
            ],
        ];

        if ($trainPercent < 10 || $trainPercent > 90) {
            return [...$result, 'reasons' => ['The training share must be between 10% and 90%.']];
        }

        [$basketCount, $baskets] = $this->basketCounter->withinSnapshot(function () use ($storeId, $fromDate, $toDate): array {
            $basketCount = (int) $this->basketCounter->eligibleItems($storeId, null, $fromDate, $toDate)
                ->distinct()
                ->count('purchase_items.purchase_id');

            if ($basketCount === 0 || $basketCount > self::MAX_BASKETS) {
                return [$basketCount, []];
            }

            return [$basketCount, $this->loadBaskets($storeId, $fromDate, $toDate)];
        });

        if ($basketCount === 0) {
            return [...$result, 'reasons' => ['There are no eligible purchase baskets in this period.']];
        }

        if ($basketCount > self::MAX_BASKETS) {
            return [...$result, 'reasons' => ['The period has more than '.self::MAX_BASKETS.' baskets. Choose a shorter period.']];
        }

        $boundaryDate = $baskets[max(0, (int) floor(count($baskets) * $trainPercent / 100) - 1)]['date'];
        $training = array_values(array_filter($baskets, fn (array $basket): bool => $basket['date'] <= $boundaryDate));
        $heldOut = array_values(array_filter($baskets, fn (array $basket): bool => $basket['date'] > $boundaryDate));
        $evaluated = array_values(array_filter($heldOut, fn (array $basket): bool => count($basket['products']) >= 2));
        $result['split'] = [
            'eligible_baskets' => count($baskets),
            'training_baskets' => count($training),
            'training_period' => [$training[0]['date'], end($training)['date']],
            'held_out_baskets' => count($heldOut),
            'held_out_period' => $heldOut === [] ? null : [$heldOut[0]['date'], end($heldOut)['date']],
            'evaluated_baskets' => count($evaluated),
            'single_item_held_out_baskets' => count($heldOut) - count($evaluated),
        ];

        $reasons = [];

        if (count($training) < $thresholds->minBaskets) {
            $reasons[] = 'The training portion has '.count($training).' baskets; the engine needs at least '.$thresholds->minBaskets.' to train.';
        }

        if ($heldOut === []) {
            $reasons[] = 'All baskets fall on or before the split day, so nothing is left to evaluate. Choose a longer period.';
        } elseif (count($evaluated) < self::MIN_EVALUATED_BASKETS) {
            $reasons[] = 'Only '.count($evaluated).' later baskets have two or more products; at least '.self::MIN_EVALUATED_BASKETS.' are needed.';
        }

        if ($reasons !== []) {
            return [...$result, 'reasons' => $reasons];
        }

        $counts = BasketCounts::fromBaskets(array_column($training, 'products'), $thresholds->minPairCount);
        $rulesByAntecedent = $this->rulesByAntecedent($this->generator->generate($counts, $thresholds));
        $popular = $counts->itemCounts;
        uksort($popular, fn (int $left, int $right): int => [$popular[$right], $left] <=> [$popular[$left], $right]);
        $activeProducts = Product::query()->where('store_id', $storeId)->where('status', Product::STATUS_ACTIVE)->pluck('id')->flip();
        $tallies = ['apriori' => ['suggested' => 0, 'hits' => 0], 'popularity' => ['suggested' => 0, 'hits' => 0]];

        foreach ($evaluated as $basket) {
            $input = array_slice($basket['products'], 0, -1);
            $hidden = end($basket['products']);
            $suggestions = [
                'apriori' => $this->aprioriSuggestions($rulesByAntecedent, $input, $activeProducts->all()),
                'popularity' => $this->popularSuggestions(array_keys($popular), $input, $activeProducts->all()),
            ];

            foreach ($suggestions as $method => $products) {
                $tallies[$method]['suggested'] += $products === [] ? 0 : 1;
                $tallies[$method]['hits'] += in_array($hidden, $products, true) ? 1 : 0;
            }
        }

        $evaluatedCount = count($evaluated);

        return [
            ...$result,
            'status' => 'available',
            'label' => $evaluatedCount < self::EXPLORATORY_BELOW ? 'exploratory' : 'offline',
            'training_rule_count' => array_sum(array_map('count', $rulesByAntecedent)),
            'thresholds' => $thresholds->toArray(),
            'methods' => array_map(fn (array $tally): array => [
                'baskets_with_suggestions' => $tally['suggested'],
                'hits' => $tally['hits'],
                'coverage' => AssociationMetrics::ratio($tally['suggested'], $evaluatedCount),
                'hit_rate' => AssociationMetrics::ratio($tally['hits'], $evaluatedCount),
                'precision' => AssociationMetrics::ratio($tally['hits'], self::K * $evaluatedCount),
                'recall' => AssociationMetrics::ratio($tally['hits'], $evaluatedCount),
            ], $tallies),
        ];
    }

    /**
     * Eligible baskets in date order, each with its distinct products in
     * entry order. Only purchase IDs, dates, and product IDs are read.
     *
     * @return list<array{id: int, date: string, products: list<int>}>
     */
    private function loadBaskets(int $storeId, string $fromDate, string $toDate): array
    {
        $rows = $this->basketCounter->eligibleItems($storeId, null, $fromDate, $toDate)
            ->select('purchase_items.purchase_id', 'purchases.purchase_date', 'purchase_items.product_id', DB::raw('min(purchase_items.id) as entry_order'))
            ->groupBy('purchase_items.purchase_id', 'purchases.purchase_date', 'purchase_items.product_id')
            ->orderBy('purchases.purchase_date')
            ->orderBy('purchase_items.purchase_id')
            ->orderBy('entry_order')
            ->get();
        $baskets = [];

        foreach ($rows as $row) {
            $id = (int) $row->purchase_id;
            $baskets[$id] ??= ['id' => $id, 'date' => substr((string) $row->purchase_date, 0, 10), 'products' => []];
            $baskets[$id]['products'][] = (int) $row->product_id;
        }

        return array_values($baskets);
    }

    /**
     * @param  list<array{antecedent: int, consequent: int, pair_count: int, confidence: string, lift: string}>  $rules
     * @return array<int, list<array{consequent: int, confidence: int, lift: int, pair_count: int}>>
     */
    private function rulesByAntecedent(array $rules): array
    {
        $byAntecedent = [];

        foreach ($rules as $rule) {
            $byAntecedent[$rule['antecedent']][] = [
                'consequent' => $rule['consequent'],
                'confidence' => (int) str_replace('.', '', $rule['confidence']),
                'lift' => (int) str_replace('.', '', $rule['lift']),
                'pair_count' => $rule['pair_count'],
            ];
        }

        return $byAntecedent;
    }

    /**
     * @param  array<int, list<array{consequent: int, confidence: int, lift: int, pair_count: int}>>  $rulesByAntecedent
     * @param  list<int>  $input
     * @param  array<int, int>  $activeProducts
     * @return list<int>
     */
    private function aprioriSuggestions(array $rulesByAntecedent, array $input, array $activeProducts): array
    {
        $best = [];
        $sortedInput = $input;
        sort($sortedInput);

        foreach ($sortedInput as $antecedent) {
            if (! isset($activeProducts[$antecedent])) {
                continue;
            }

            foreach ($rulesByAntecedent[$antecedent] ?? [] as $rule) {
                $candidate = $rule['consequent'];

                if (in_array($candidate, $input, true) || ! isset($activeProducts[$candidate])) {
                    continue;
                }

                $key = [$rule['confidence'], $rule['lift'], $rule['pair_count']];

                if (! isset($best[$candidate]) || $key > $best[$candidate]) {
                    $best[$candidate] = $key;
                }
            }
        }

        uksort($best, fn (int $left, int $right): int => [$best[$right], $left] <=> [$best[$left], $right]);

        return array_slice(array_keys($best), 0, self::K);
    }

    /**
     * @param  list<int>  $ranked
     * @param  list<int>  $input
     * @param  array<int, int>  $activeProducts
     * @return list<int>
     */
    private function popularSuggestions(array $ranked, array $input, array $activeProducts): array
    {
        $suggestions = [];

        foreach ($ranked as $product) {
            if (! in_array($product, $input, true) && isset($activeProducts[$product])) {
                $suggestions[] = $product;

                if (count($suggestions) === self::K) {
                    break;
                }
            }
        }

        return $suggestions;
    }
}
