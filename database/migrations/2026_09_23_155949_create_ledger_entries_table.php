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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->json('itemized_list')->nullable();
            $table->date('transaction_date');
            $table->date('repayment_deadline');
            $table->decimal('running_balance', 12, 2);
            $table->timestamps();

            $table->index(['customer_id', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
