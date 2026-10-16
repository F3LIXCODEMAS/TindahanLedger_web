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
     * A customer email must be unique within its store, so independent stores
     * may each keep a record for the same person.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['store_id', 'email']);
            $table->dropUnique(['email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $sharedEmailExists = DB::table('customers')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($sharedEmailExists) {
            throw new RuntimeException('Cannot restore global customer email uniqueness while stores share a customer email.');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('email');
            $table->dropUnique(['store_id', 'email']);
        });
    }
};
