<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RULE_SET_SCOPE_CHECK = 'rule_sets_scope_check';

    private const RULE_ITEMS_CHECK = 'association_rules_items_check';

    /**
     * Versioned single-item association rules (DUMS-39).
     *
     * `rule_sets` holds one row per training run. `scope_key` names the model
     * scope (one store's purchases, or one external experiment file).
     * `active_scope_key` equals `scope_key` only on the active version and
     * is null otherwise; its unique index makes a second active version for
     * the same scope impossible.
     *
     * `association_rules` stores each rule A → B with the counts behind its
     * metrics. Store rules reference catalog products; composite foreign
     * keys keep the rule, its rule set, and both products in one store.
     * External experiment rules have no store and use normalized labels
     * instead, so external data never enters the product catalog.
     *
     * Deletes are restricted everywhere: a product with rules cannot be
     * deleted (matching how purchase history protects products), and a rule
     * set with rules cannot be deleted, so earlier versions stay available
     * for auditing. Nothing in the application deletes rule sets.
     *
     * On MySQL/MariaDB CHECK constraints tie the scope to its store and
     * forbid self-rules and mixed product/label rows. SQLite cannot add
     * them, so there the rules are enforced by the services.
     */
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 30);
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope_key', 100);
            $table->string('active_scope_key', 100)->nullable()->unique();
            $table->string('source_type', 30);
            $table->string('source_identifier', 100)->nullable();
            $table->json('source_details')->nullable();
            $table->unsignedBigInteger('source_cutoff_purchase_id')->nullable();
            $table->string('algorithm', 50);
            $table->string('algorithm_version', 20);
            $table->json('parameters');
            $table->string('status', 20);
            $table->unsignedInteger('eligible_basket_count')->nullable();
            $table->unsignedInteger('distinct_item_count')->nullable();
            $table->unsignedInteger('candidate_pair_count')->nullable();
            $table->unsignedInteger('rule_count')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'store_id'], 'rule_sets_id_store_id_unique');
            $table->index(['scope_key', 'status']);
        });

        Schema::create('association_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('antecedent_product_id')->nullable();
            $table->unsignedBigInteger('consequent_product_id')->nullable();
            $antecedentLabel = $table->string('antecedent_label', 120)->nullable();
            $consequentLabel = $table->string('consequent_label', 120)->nullable();
            $table->unsignedInteger('antecedent_count');
            $table->unsignedInteger('consequent_count');
            $table->unsignedInteger('pair_count');
            $table->decimal('support', 8, 6);
            $table->decimal('confidence', 8, 6);
            $table->decimal('lift', 14, 6);
            $table->timestamp('created_at')->nullable();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $antecedentLabel->collation('utf8mb4_bin');
                $consequentLabel->collation('utf8mb4_bin');
            }

            $table->unique(['rule_set_id', 'antecedent_product_id', 'consequent_product_id'], 'association_rules_set_products_unique');
            $table->unique(['rule_set_id', 'antecedent_label', 'consequent_label'], 'association_rules_set_labels_unique');

            $table->foreign(['rule_set_id', 'store_id'], 'association_rules_rule_set_store_foreign')
                ->references(['id', 'store_id'])
                ->on('rule_sets')
                ->restrictOnDelete();
            $table->foreign(['antecedent_product_id', 'store_id'], 'association_rules_antecedent_store_foreign')
                ->references(['id', 'store_id'])
                ->on('products')
                ->restrictOnDelete();
            $table->foreign(['consequent_product_id', 'store_id'], 'association_rules_consequent_store_foreign')
                ->references(['id', 'store_id'])
                ->on('products')
                ->restrictOnDelete();
        });

        if ($this->supportsCheckConstraints()) {
            DB::statement('alter table rule_sets add constraint '.self::RULE_SET_SCOPE_CHECK.' check ('
                ."(scope_type = 'store' and store_id is not null)"
                ." or (scope_type = 'external_experiment' and store_id is null and active_scope_key is null)"
                .')');
            DB::statement('alter table association_rules add constraint '.self::RULE_ITEMS_CHECK.' check ('
                .'(store_id is not null and antecedent_product_id is not null and consequent_product_id is not null'
                .' and antecedent_product_id <> consequent_product_id and antecedent_label is null and consequent_label is null)'
                .' or (store_id is null and antecedent_product_id is null and consequent_product_id is null'
                .' and antecedent_label is not null and consequent_label is not null and antecedent_label <> consequent_label)'
                .')');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('association_rules');
        Schema::dropIfExists('rule_sets');
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
