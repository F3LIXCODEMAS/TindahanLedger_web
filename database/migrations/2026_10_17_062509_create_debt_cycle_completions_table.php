<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an audit history of completed debt cycles and the three-cycle
     * reward, plus running totals of cash repaid in the current cycle and the
     * current streak (the reward is based on the cash repaid during the
     * streak's cycles).
     *
     * Existing data: the cycle counters already recorded are kept, because
     * the ledger cannot be replayed reliably when credits and payments share
     * a timestamp. Customers previously flagged as reward eligible receive
     * the reward once now, based on their lifetime cash repaid, and are
     * recorded with a `legacy_reward` history row. Streak totals are filled
     * exactly for customers who never passed the threshold (all their cash
     * repaid belongs to the current streak) and start at zero otherwise.
     */
    public function up(): void
    {
        Schema::create('debt_cycle_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('completing_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->unsignedTinyInteger('streak_position');
            $table->unsignedBigInteger('repaid_cents');
            $table->unsignedInteger('reward_points')->default(0);
            $table->string('source', 20)->default('payment');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['customer_id', 'cycle_number']);
            $table->index(['customer_id', 'completed_at']);
        });

        Schema::table('suki_points', function (Blueprint $table) {
            $table->unsignedBigInteger('cycle_repaid_cents')->default(0)->after('repayment_remainder_cents');
            $table->unsignedBigInteger('streak_repaid_cents')->default(0)->after('cycle_repaid_cents');
        });

        $centsPerRewardPoint = max(1, (int) config('suki_points.cycle_reward_pesos_per_point')) * 100;
        $now = now();

        DB::transaction(function () use ($centsPerRewardPoint, $now): void {
            foreach (DB::table('suki_points')->orderBy('id')->lazyById() as $sukiPoints) {
                $cashPayments = DB::table('payments')->where('customer_id', $sukiPoints->customer_id);

                if (Schema::hasColumn('payments', 'kind')) {
                    $cashPayments->where('kind', 'cash');
                }

                $lifetimeCashCents = (int) round(((float) $cashPayments->sum('amount')) * 100);
                $neverPassedThreshold = (int) $sukiPoints->completed_cycles === (int) $sukiPoints->current_cycle_count;
                $updates = [
                    'cycle_repaid_cents' => (int) $sukiPoints->completed_cycles === 0 ? $lifetimeCashCents : 0,
                    'streak_repaid_cents' => $neverPassedThreshold ? $lifetimeCashCents : 0,
                ];

                if ($sukiPoints->reward_eligible) {
                    $rewardPoints = intdiv($lifetimeCashCents, $centsPerRewardPoint);

                    DB::table('debt_cycle_completions')->insert([
                        'customer_id' => $sukiPoints->customer_id,
                        'completing_payment_id' => null,
                        'cycle_number' => max(1, (int) $sukiPoints->completed_cycles),
                        'streak_position' => (int) $sukiPoints->reward_threshold,
                        'repaid_cents' => $lifetimeCashCents,
                        'reward_points' => $rewardPoints,
                        'source' => 'legacy_reward',
                        'completed_at' => $sukiPoints->updated_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $updates['points_balance'] = (int) $sukiPoints->points_balance + $rewardPoints;
                    $updates['streak_repaid_cents'] = 0;
                    $updates['reward_eligible'] = false;
                }

                DB::table('suki_points')->where('id', $sukiPoints->id)->update($updates);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Legacy rewards are taken back and the eligibility flag restored. Once a
     * reward has been earned through a live payment, the rollback refuses to
     * run, because removing the history would leave unexplained points.
     */
    public function down(): void
    {
        if (DB::table('debt_cycle_completions')->where('source', 'payment')->where('reward_points', '>', 0)->exists()) {
            throw new RuntimeException('Cannot remove cycle history while customers hold cycle rewards earned from payments.');
        }

        DB::transaction(function (): void {
            foreach (DB::table('debt_cycle_completions')->where('source', 'legacy_reward')->get() as $legacyReward) {
                DB::table('suki_points')->where('customer_id', $legacyReward->customer_id)->update([
                    'points_balance' => DB::raw('points_balance - '.(int) $legacyReward->reward_points),
                    'reward_eligible' => true,
                ]);
            }
        });

        Schema::table('suki_points', function (Blueprint $table) {
            $table->dropColumn(['cycle_repaid_cents', 'streak_repaid_cents']);
        });

        Schema::dropIfExists('debt_cycle_completions');
    }
};
