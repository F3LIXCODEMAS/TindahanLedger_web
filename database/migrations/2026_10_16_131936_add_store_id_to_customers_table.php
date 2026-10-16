<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column starts nullable so existing customers can be assigned to a
     * store by the following backfill migration before it becomes required.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->restrictOnDelete();

            $table->index(['store_id', 'full_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropIndex(['store_id', 'full_name']);
            $table->dropColumn('store_id');
        });
    }
};
