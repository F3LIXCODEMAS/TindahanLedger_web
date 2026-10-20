<?php

use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPoint;
use Illuminate\Support\Facades\DB;

/**
 * Roll back the cycle history migration so suki_points rows shaped like the
 * earlier schema can be inserted, then return the migration instance.
 */
function revertCycleHistoryMigration(): object
{
    $migration = require database_path('migrations/2026_10_17_062509_create_debt_cycle_completions_table.php');
    $migration->down();

    return $migration;
}

function legacySukiPoints(Customer $customer, array $counters, string $cashRepaid): void
{
    $entry = LedgerEntry::factory()->for($customer)->create(['amount' => $cashRepaid]);
    Payment::factory()->for($customer)->for($entry)->create(['amount' => $cashRepaid]);
    DB::table('suki_points')->insert([
        'customer_id' => $customer->id,
        'completed_cycles' => 0,
        'current_cycle_count' => 0,
        'reward_threshold' => 3,
        'points_balance' => 7,
        'repayment_remainder_cents' => 0,
        'reward_eligible' => false,
        'created_at' => now(),
        'updated_at' => now(),
        ...$counters,
    ]);
}

it('gives customers flagged as reward eligible the reward once from their cash repaid', function () {
    $migration = revertCycleHistoryMigration();
    $customer = Customer::factory()->create();
    legacySukiPoints($customer, ['completed_cycles' => 3, 'current_cycle_count' => 0, 'reward_eligible' => true], '850.00');

    $migration->up();

    $sukiPoints = SukiPoint::query()->where('customer_id', $customer->id)->sole();
    expect($sukiPoints->points_balance)->toBe(7 + 8);
    expect($sukiPoints->reward_eligible)->toBeFalse();
    expect($sukiPoints->completed_cycles)->toBe(3);
    expect($sukiPoints->streak_repaid_cents)->toBe(0);
    expect(DebtCycleCompletion::query()->sole())
        ->source->toBe(DebtCycleCompletion::SOURCE_LEGACY_REWARD)
        ->cycle_number->toBe(3)
        ->repaid_cents->toBe(85000)
        ->reward_points->toBe(8);
});

it('keeps recorded counters and fills the streak total for customers who never reached a reward', function () {
    $migration = revertCycleHistoryMigration();
    $customer = Customer::factory()->create();
    legacySukiPoints($customer, ['completed_cycles' => 2, 'current_cycle_count' => 2], '450.00');

    $migration->up();

    expect(SukiPoint::query()->where('customer_id', $customer->id)->sole())
        ->points_balance->toBe(7)
        ->completed_cycles->toBe(2)
        ->current_cycle_count->toBe(2)
        ->streak_repaid_cents->toBe(45000)
        ->cycle_repaid_cents->toBe(0);
    expect(DebtCycleCompletion::query()->count())->toBe(0);
});

it('takes legacy rewards back and restores the flag when rolled back', function () {
    $migration = revertCycleHistoryMigration();
    $customer = Customer::factory()->create();
    legacySukiPoints($customer, ['completed_cycles' => 3, 'reward_eligible' => true], '850.00');
    $migration->up();

    $migration->down();

    expect(DB::table('suki_points')->where('customer_id', $customer->id)->first())
        ->points_balance->toEqual(7)
        ->reward_eligible->toEqual(1);
});
