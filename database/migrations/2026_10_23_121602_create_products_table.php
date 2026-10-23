<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store-specific product catalog (DUMS-35).
     *
     * `normalized_name` holds the trimmed, lowercased name and is unique per
     * store. On MySQL/MariaDB it uses a binary collation so the database
     * agrees exactly with the application's normalization instead of the
     * table's accent- and case-insensitive collation; SQLite already compares
     * exactly by default.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $normalizedName = $table->string('normalized_name', 120);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $normalizedName->collation('utf8mb4_bin');
            }

            $table->unique(['store_id', 'normalized_name']);
            $table->index(['store_id', 'status', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
