<?php

namespace App\Services;

use App\Models\RuleSet;
use App\Models\Store;
use App\Support\AssociationRuleThresholds;

/**
 * Describes a store's recommendation model from persisted rule-set
 * metadata only (DUMS-41). Nothing is estimated from current purchases,
 * and nothing is trained or activated.
 *
 * Only store-specific models exist: the recommendation service reads the
 * store's own active rule set and has no global fallback. External
 * experiment rule sets are never activated, so they never appear here.
 */
class RecommendationModelStatusService
{
    /**
     * @return array{
     *     status: string,
     *     active: RuleSet|null,
     *     active_rule_count: int|null,
     *     latest_run: RuleSet|null,
     *     scope: string,
     *     global_fallback: bool,
     *     configured_thresholds: array<string, mixed>,
     *     thresholds_changed_since_training: bool
     * }
     */
    public function forStore(Store $store): array
    {
        $storeId = (int) $store->getKey();
        $active = RuleSet::query()->activeForStore($storeId)->first();
        $latestRun = RuleSet::query()
            ->where('scope_key', RuleSet::storeScopeKey($storeId))
            ->latest('id')
            ->first();
        $configured = AssociationRuleThresholds::fromConfig()->toArray();

        return [
            'status' => $active !== null ? RuleSet::STATUS_ACTIVE : ($latestRun?->status ?? 'not_trained'),
            'active' => $active,
            'active_rule_count' => $active?->rules()->count(),
            'latest_run' => $latestRun,
            'scope' => RuleSet::SCOPE_STORE,
            'global_fallback' => false,
            'configured_thresholds' => $configured,
            'thresholds_changed_since_training' => $active !== null && $this->thresholdsDiffer($active->parameters ?? [], $configured),
        ];
    }

    /**
     * @param  array<string, mixed>  $trained
     * @param  array<string, mixed>  $configured
     */
    private function thresholdsDiffer(array $trained, array $configured): bool
    {
        foreach (['min_support', 'min_confidence', 'min_lift', 'min_baskets', 'min_antecedent_count', 'min_pair_count'] as $key) {
            if ((string) ($trained[$key] ?? '') !== (string) ($configured[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
