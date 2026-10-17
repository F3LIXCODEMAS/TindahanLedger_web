<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Models\SukiPoint;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Read-only Suki Points information for display (DUMS-34). All values come
 * from what LedgerService recorded; nothing is recalculated here. Callers
 * must authorize access to the customer first (CustomerPolicy or the
 * customer portal session).
 */
class SukiPointService
{
    /**
     * @return array{
     *     points_balance: int,
     *     completed_cycles: int,
     *     current_streak: int,
     *     reward_threshold: int,
     *     cycles_until_reward: int,
     *     streak_repaid: string,
     *     projected_reward_points: int,
     *     repayment_pesos_per_point: int,
     *     cycle_reward_pesos_per_point: int,
     *     total_cycle_reward_points: int,
     *     recent_cycles: list<array{cycle_number: int, streak_position: int, repaid: string, reward_points: int, is_legacy_reward: bool, completed_at: Carbon}>
     * }
     */
    public function summaryFor(Customer $customer, int $historyLimit = 10): array
    {
        $sukiPoints = $customer->sukiPoints()->first() ?? new SukiPoint;
        $rewardThreshold = max(1, $sukiPoints->reward_threshold);
        $cycleRewardPesosPerPoint = max(1, (int) config('suki_points.cycle_reward_pesos_per_point'));

        return [
            'points_balance' => $sukiPoints->points_balance,
            'completed_cycles' => $sukiPoints->completed_cycles,
            'current_streak' => $sukiPoints->current_cycle_count,
            'reward_threshold' => $rewardThreshold,
            'cycles_until_reward' => max(0, $rewardThreshold - $sukiPoints->current_cycle_count),
            'streak_repaid' => Money::fromCents($sukiPoints->streak_repaid_cents),
            'projected_reward_points' => intdiv($sukiPoints->streak_repaid_cents, $cycleRewardPesosPerPoint * 100),
            'repayment_pesos_per_point' => intdiv(SukiPoint::CENTS_PER_POINT, 100),
            'cycle_reward_pesos_per_point' => $cycleRewardPesosPerPoint,
            'total_cycle_reward_points' => (int) $customer->debtCycleCompletions()->sum('reward_points'),
            'recent_cycles' => $customer->debtCycleCompletions()
                ->latest('cycle_number')
                ->limit($historyLimit)
                ->get()
                ->map(fn (DebtCycleCompletion $completion): array => [
                    'cycle_number' => $completion->cycle_number,
                    'streak_position' => $completion->streak_position,
                    'repaid' => Money::fromCents($completion->repaid_cents),
                    'reward_points' => $completion->reward_points,
                    'is_legacy_reward' => $completion->source === DebtCycleCompletion::SOURCE_LEGACY_REWARD,
                    'completed_at' => $completion->completed_at,
                ])
                ->all(),
        ];
    }
}
