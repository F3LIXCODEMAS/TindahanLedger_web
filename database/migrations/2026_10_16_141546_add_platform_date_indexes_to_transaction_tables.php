<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Platform-wide transaction monitoring filters and sorts by date across
     * every customer. The existing indexes lead with customer_id, so they
     * cannot serve a date range on its own.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['transaction_date', 'id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['payment_date', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_date', 'id']);
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['transaction_date', 'id']);
        });
    }
};
