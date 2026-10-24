<?php

namespace App\Services;

use App\Support\AssociationMetrics;
use App\Support\AssociationRuleThresholds;
use App\Support\BasketCounts;

/**
 * Turns basket counts into single-item rules A → B (DUMS-39).
 *
 * Every co-occurring pair is evaluated in both directions, because A → B
 * and B → A share support and lift but usually differ in confidence. A
 * rule is kept when it meets every threshold (inclusive): co-occurrence
 * count, antecedent count, support, confidence, and lift when enabled.
 * Self-rules cannot occur. This is single-item pair counting, not the full
 * iterative Apriori search for larger itemsets.
 *
 * The caller checks the minimum basket count first.
 */
class AssociationRuleGenerator
{
    /**
     * @return list<array{antecedent: int, consequent: int, antecedent_count: int, consequent_count: int, pair_count: int, support: string, confidence: string, lift: string}>
     */
    public function generate(BasketCounts $counts, AssociationRuleThresholds $thresholds): array
    {
        $counts->assertConsistent();
        $rules = [];

        foreach ($counts->pairCounts as [$first, $second, $pairCount]) {
            if ($first === $second || $pairCount < $thresholds->minPairCount) {
                continue;
            }

            if (! AssociationMetrics::ratioMeets($pairCount, $counts->basketCount, $thresholds->minSupportUnits)) {
                continue;
            }

            foreach ([[$first, $second], [$second, $first]] as [$antecedent, $consequent]) {
                $rule = $this->evaluate($counts, $thresholds, $antecedent, $consequent, $pairCount);

                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        usort($rules, fn (array $left, array $right): int => [$left['antecedent'], $left['consequent']] <=> [$right['antecedent'], $right['consequent']]);

        return $rules;
    }

    /**
     * @return array{antecedent: int, consequent: int, antecedent_count: int, consequent_count: int, pair_count: int, support: string, confidence: string, lift: string}|null
     */
    private function evaluate(BasketCounts $counts, AssociationRuleThresholds $thresholds, int $antecedent, int $consequent, int $pairCount): ?array
    {
        $antecedentCount = $counts->itemCounts[$antecedent];
        $consequentCount = $counts->itemCounts[$consequent];

        if ($antecedentCount < $thresholds->minAntecedentCount
            || ! AssociationMetrics::ratioMeets($pairCount, $antecedentCount, $thresholds->minConfidenceUnits)) {
            return null;
        }

        if ($thresholds->minLiftUnits !== null
            && ! AssociationMetrics::liftMeets($pairCount, $counts->basketCount, $antecedentCount, $consequentCount, $thresholds->minLiftUnits)) {
            return null;
        }

        return [
            'antecedent' => $antecedent,
            'consequent' => $consequent,
            'antecedent_count' => $antecedentCount,
            'consequent_count' => $consequentCount,
            'pair_count' => $pairCount,
            'support' => AssociationMetrics::support($pairCount, $counts->basketCount),
            'confidence' => AssociationMetrics::confidence($pairCount, $antecedentCount),
            'lift' => AssociationMetrics::lift($pairCount, $counts->basketCount, $antecedentCount, $consequentCount),
        ];
    }
}
