<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPE_CHECK = 'purchases_type_links_check';

    /**
     * Cash purchases (DUMS-37) are product sales paid on the spot: they
     * have no ledger entry and may have no customer (a walk-in sale).
     *
     * - `customer_id` and `ledger_entry_id` become nullable. The existing
     *   composite foreign keys still apply whenever they are set, and the
     *   unique ledger entry keeps one purchase per utang credit.
     * - `idempotency_key` stops a resubmitted cash sale from being saved
     *   twice; it is unique per store. Utang purchases leave it empty because
     *   their ledger entry already carries the submission key.
     * - On MySQL/MariaDB a CHECK constraint ties the type to its links:
     *   utang needs a customer and a ledger entry; cash has no ledger entry
     *   and needs an idempotency key. SQLite cannot add CHECK constraints to
     *   an existing table, so there the rule is enforced by the services.
     *
     * Rolling back fails while cash purchases exist, because their empty
     * customer and ledger entry cannot become required again.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->unsignedBigInteger('ledger_entry_id')->nullable()->change();
            $table->uuid('idempotency_key')->nullable()->after('recorded_by_user_id');

            $table->unique(['store_id', 'idempotency_key']);
        });

        if ($this->supportsCheckConstraints()) {
            DB::statement('alter table purchases add constraint '.self::TYPE_CHECK.' check ('
                ."(purchase_type = 'utang' and ledger_entry_id is not null and customer_id is not null)"
                ." or (purchase_type = 'cash' and ledger_entry_id is null and idempotency_key is not null)"
                .')');
        }
    }

    public function down(): void
    {
        if ($this->supportsCheckConstraints()) {
            DB::statement('alter table purchases drop constraint '.self::TYPE_CHECK);
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->unsignedBigInteger('ledger_entry_id')->nullable(false)->change();
        });
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
