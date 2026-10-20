<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * QR access credentials for the customer self-service portal. The store is
     * inherited through the customer. Lookups use a SHA-256 hash of the token;
     * the token itself is kept only in encrypted form so the owner can reprint
     * the same QR code, and it is erased when the credential is revoked.
     * `active_customer_id` is set only while a credential is active, so its
     * unique index allows at most one active credential per customer.
     */
    public function up(): void
    {
        Schema::create('customer_access_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_customer_id')->nullable()->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('active_customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_access_credentials');
    }
};
