<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured purchase details for utang credits (DUMS-36).
     *
     * A purchase describes what was bought on one ledger credit; it never
     * changes what is owed. Composite foreign keys keep every purchase and
     * purchase item inside one store: the customer, ledger entry, and each
     * product must belong to the purchase's store. History is protected
     * with restrictive deletes.
     *
     * Precision: quantity is decimal(8,3) (up to 1,000 with three decimal
     * places, validated in the application). Unit prices use the catalog's
     * decimal(12,2). Line totals use decimal(15,2) because 1,000 units at the
     * largest allowed price exceed decimal(12,2).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['id', 'store_id'], 'customers_id_store_id_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['id', 'store_id'], 'products_id_store_id_unique');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('ledger_entry_id')->unique();
            $table->string('purchase_type', 20);
            $table->date('purchase_date');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'store_id'], 'purchases_id_store_id_unique');
            $table->index(['store_id', 'purchase_date']);

            $table->foreign(['customer_id', 'store_id'], 'purchases_customer_store_foreign')
                ->references(['id', 'store_id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->foreign(['ledger_entry_id', 'customer_id'], 'purchases_ledger_entry_customer_foreign')
                ->references(['id', 'customer_id'])
                ->on('ledger_entries')
                ->restrictOnDelete();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 120);
            $table->decimal('quantity', 8, 3);
            $table->decimal('unit_price_snapshot', 12, 2)->nullable();
            $table->decimal('line_total', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['purchase_id', 'product_id']);
            $table->index(['store_id', 'product_id']);

            $table->foreign(['purchase_id', 'store_id'], 'purchase_items_purchase_store_foreign')
                ->references(['id', 'store_id'])
                ->on('purchases')
                ->restrictOnDelete();
            $table->foreign(['product_id', 'store_id'], 'purchase_items_product_store_foreign')
                ->references(['id', 'store_id'])
                ->on('products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_id_store_id_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_id_store_id_unique');
        });
    }
};
