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
     * A late payment penalty is stored as its own ledger entry (kind
     * `late_penalty`) linked to the credit it was charged on, so every
     * existing balance, oldest-first payment and overdue calculation includes
     * it without changing the original credit. The unique link makes a second
     * penalty on the same credit impossible.
     *
     * Each new credit stores the penalty policy it was accepted under
     * (`penalty_policy_snapshot`). Existing credits keep NULL and are never
     * penalised, so nothing is applied retroactively. Existing rows become
     * kind `credit`; no amount, date or payment is changed.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('kind', 20)->default('credit')->after('customer_id');
            $table->foreignId('penalty_for_entry_id')->nullable()->unique()->after('kind')
                ->constrained('ledger_entries')->restrictOnDelete();
            $table->json('penalty_policy_snapshot')->nullable()->after('repayment_deadline');
            $table->timestamp('penalty_waived_at')->nullable()->after('penalty_policy_snapshot');
            $table->foreignId('penalty_waived_by_user_id')->nullable()->after('penalty_waived_at')
                ->constrained('users')->restrictOnDelete();
            $table->string('penalty_waiver_reason', 500)->nullable()->after('penalty_waived_by_user_id');

            $table->index(['kind', 'repayment_deadline']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Refuses while penalties exist, because dropping `kind` would turn them
     * into apparent credits.
     */
    public function down(): void
    {
        if (DB::table('ledger_entries')->where('kind', '!=', 'credit')->exists()) {
            throw new RuntimeException('Cannot remove penalty support while late penalty records exist.');
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['kind', 'repayment_deadline']);
            $table->dropForeign(['penalty_for_entry_id']);
            $table->dropForeign(['penalty_waived_by_user_id']);
            $table->dropUnique(['penalty_for_entry_id']);
            $table->dropColumn([
                'kind',
                'penalty_for_entry_id',
                'penalty_policy_snapshot',
                'penalty_waived_at',
                'penalty_waived_by_user_id',
                'penalty_waiver_reason',
            ]);
        });
    }
};
