<?php

namespace App\Services;

use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishes a validated store rule set (DUMS-39). In one transaction, with
 * the scope's rule sets locked, the current active version becomes
 * superseded and the candidate becomes active. If anything fails the
 * transaction rolls back and the previous version stays active. The unique
 * `active_scope_key` makes two active versions of one scope impossible.
 */
class RuleSetActivator
{
    public function activate(RuleSet $candidate): RuleSet
    {
        return DB::transaction(function () use ($candidate): RuleSet {
            $scopeRuleSets = RuleSet::query()
                ->where('scope_key', $candidate->scope_key)
                ->whereIn('status', [RuleSet::STATUS_VALIDATED, RuleSet::STATUS_ACTIVE])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $locked = $scopeRuleSets->get($candidate->getKey());

            if ($locked === null || $locked->status !== RuleSet::STATUS_VALIDATED) {
                throw new RuntimeException('Only a validated rule set can be activated.');
            }

            if ($locked->scope_type !== RuleSet::SCOPE_STORE) {
                throw new RuntimeException('External experiment rule sets are never activated.');
            }

            $active = $scopeRuleSets->first(fn (RuleSet $ruleSet): bool => $ruleSet->status === RuleSet::STATUS_ACTIVE);

            if ($active !== null && (int) $active->getKey() > (int) $locked->getKey()) {
                throw new RuntimeException('A newer rule set is already active.');
            }

            $now = now();

            RuleSet::query()
                ->where('scope_key', $locked->scope_key)
                ->where('status', RuleSet::STATUS_ACTIVE)
                ->update([
                    'status' => RuleSet::STATUS_SUPERSEDED,
                    'active_scope_key' => null,
                    'superseded_at' => $now,
                    'updated_at' => $now,
                ]);

            $locked->forceFill([
                'status' => RuleSet::STATUS_ACTIVE,
                'active_scope_key' => $locked->scope_key,
                'activated_at' => $now,
            ])->save();

            return $locked;
        });
    }
}
