<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing accounts default to the store-owner role. No account is
     * promoted to Super Admin automatically; use `php artisan admin:create`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 120)->nullable()->unique()->after('name');
            $table->string('role', 20)->default('store_owner')->after('password');
            $table->string('status', 20)->default('active')->after('role');
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');

            $table->index(['role', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'status']);
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role', 'status', 'must_change_password', 'last_login_at']);
        });
    }
};
