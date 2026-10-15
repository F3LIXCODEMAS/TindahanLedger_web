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
        Schema::create('email_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_entry_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_type', 24);
            $table->date('scheduled_reminder_date');
            $table->date('repayment_deadline');
            $table->decimal('outstanding_amount', 12, 2);
            $table->string('delivery_status', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['ledger_entry_id', 'reminder_type', 'scheduled_reminder_date'],
                'email_reminder_logs_trigger_unique',
            );
            $table->index(['customer_id', 'scheduled_reminder_date']);
            $table->index(
                ['delivery_status', 'scheduled_reminder_date'],
                'email_reminder_logs_status_date_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_reminder_logs');
    }
};
