<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->date('repayment_deadline')->nullable()->change();
            $table->text('note')->nullable()->after('itemized_list');
            $table->uuid('idempotency_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ledger_entries', 'repayment_deadline')
            && DB::table('ledger_entries')->whereNull('repayment_deadline')->exists()) {
            throw new RuntimeException('Cannot restore a required repayment deadline while credit records have no deadline.');
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['note', 'idempotency_key']);
            $table->date('repayment_deadline')->nullable(false)->change();
        });
    }
};
