<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables whose rows reference both a customer and a ledger entry.
     *
     * @var list<string>
     */
    private const DEPENDENT_TABLES = ['payments', 'email_reminder_logs'];

    /**
     * Run the migrations.
     *
     * Store ownership is inherited through the customer, so a payment or
     * reminder log must reference a ledger entry belonging to that same
     * customer. Composite foreign keys make a mismatched row impossible to
     * persist. Existing mismatches stop the migration instead of being changed.
     */
    public function up(): void
    {
        foreach (self::DEPENDENT_TABLES as $dependentTable) {
            $mismatchCount = DB::table($dependentTable)
                ->join('ledger_entries', 'ledger_entries.id', '=', $dependentTable.'.ledger_entry_id')
                ->whereColumn($dependentTable.'.customer_id', '!=', 'ledger_entries.customer_id')
                ->count();

            if ($mismatchCount > 0) {
                throw new RuntimeException(
                    "Found {$mismatchCount} {$dependentTable} rows whose customer does not own the referenced ledger entry. Resolve them before migrating.",
                );
            }
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unique(['id', 'customer_id'], 'ledger_entries_id_customer_id_unique');
        });

        foreach (self::DEPENDENT_TABLES as $dependentTable) {
            Schema::table($dependentTable, function (Blueprint $table) use ($dependentTable) {
                $table->foreign(['ledger_entry_id', 'customer_id'], $dependentTable.'_ledger_entry_customer_foreign')
                    ->references(['id', 'customer_id'])
                    ->on('ledger_entries')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::DEPENDENT_TABLES as $dependentTable) {
            Schema::table($dependentTable, function (Blueprint $table) use ($dependentTable) {
                $table->dropForeign($dependentTable.'_ledger_entry_customer_foreign');
            });
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_entries_id_customer_id_unique');
        });
    }
};
