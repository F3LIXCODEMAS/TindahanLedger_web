<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('suki_points')
            ->whereNull('created_at')
            ->update([
                'created_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Repaired creation timestamps are retained on rollback.
    }
};
