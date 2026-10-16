<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Runs after the store backfill, which guarantees every customer has a
     * store and every user has a username before these become required.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 120)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 120)->nullable()->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
        });
    }
};
