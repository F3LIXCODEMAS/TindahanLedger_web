<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One late payment policy per store, disabled until the owner turns it
     * on. `version` increases on every change; credits record the version
     * and values they were accepted under.
     */
    public function up(): void
    {
        Schema::create('store_penalty_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('fee_cents');
            $table->unsignedTinyInteger('grace_days');
            $table->text('disclosure_text')->nullable();
            $table->date('effective_from');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_penalty_policies');
    }
};
