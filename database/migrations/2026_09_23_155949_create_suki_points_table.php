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
        Schema::create('suki_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('completed_cycles')->default(0);
            $table->unsignedTinyInteger('current_cycle_count')->default(0);
            $table->unsignedTinyInteger('reward_threshold')->default(3);
            $table->unsignedInteger('points_balance')->default(0);
            $table->boolean('reward_eligible')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suki_points');
    }
};
