<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('note')->nullable()->after('payment_date');
            $table->uuid('payment_batch_id')->nullable()->index();
            $table->uuid('idempotency_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['payment_batch_id']);
            $table->dropColumn(['note', 'payment_batch_id', 'idempotency_key']);
        });
    }
};
