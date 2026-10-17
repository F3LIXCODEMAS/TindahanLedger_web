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
     * Suki Points can be redeemed as a non-cash credit against a customer's
     * debt. Redemptions are stored as payment allocations with kind
     * `loyalty_redemption`, so every existing balance, available-credit, and
     * overdue calculation includes them, while cash reports exclude them.
     * Existing payments default to `cash`; no historical row is changed.
     *
     * Each redemption also writes one points-ledger row in
     * suki_point_transactions, linked to its first allocation, recording the
     * points used, the peso value, and the owner who recorded it.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('kind', 30)->default('cash')->after('amount');

            $table->index(['kind', 'payment_date']);
        });

        Schema::table('suki_point_transactions', function (Blueprint $table) {
            $table->string('transaction_type', 20)->default('earned')->after('payment_id');
            $table->unsignedInteger('points_redeemed')->default(0)->after('points_awarded');
            $table->unsignedBigInteger('redeemed_amount_cents')->default(0)->after('points_redeemed');
            $table->foreignId('recorded_by_user_id')->nullable()->after('remainder_after_cents')
                ->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Refuses to run while redemptions exist, because dropping the kind
     * column would turn them into apparent cash payments.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payments', 'kind')
            && DB::table('payments')->where('kind', '!=', 'cash')->exists()) {
            throw new RuntimeException('Cannot remove loyalty redemption support while redemption records exist.');
        }

        Schema::table('suki_point_transactions', function (Blueprint $table) {
            $table->dropForeign(['recorded_by_user_id']);
            $table->dropColumn(['transaction_type', 'points_redeemed', 'redeemed_amount_cents', 'recorded_by_user_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['kind', 'payment_date']);
            $table->dropColumn('kind');
        });
    }
};
