<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Moves the original single-owner data into an explicit store. When the
     * database already holds customers, exactly one existing account must be
     * present so the owner can be identified without guessing; otherwise the
     * migration stops before changing any data. Record counts and financial
     * totals are compared before and after the assignment.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $snapshotBefore = $this->financialSnapshot();
            $userIds = DB::table('users')->orderBy('id')->pluck('id');
            $unassignedCustomerCount = DB::table('customers')->whereNull('store_id')->count();

            if ($unassignedCustomerCount > 0 && $userIds->count() !== 1) {
                throw new RuntimeException(sprintf(
                    'Cannot assign %d existing customers to a store: expected exactly one existing owner account, found %d. Resolve the owner manually before migrating.',
                    $unassignedCustomerCount,
                    $userIds->count(),
                ));
            }

            if ($userIds->count() === 1) {
                $ownerId = $userIds->first();
                $owner = DB::table('users')->where('id', $ownerId)->first(['id', 'name']);
                $storeName = DB::table('store_email_settings')->where('user_id', $ownerId)->value('store_name')
                    ?? Str::limit($owner->name, 110, '')."'s Store";
                $storeName = Str::squish($storeName);
                $now = now();

                $storeId = DB::table('stores')->insertGetId([
                    'store_name' => $storeName,
                    'owner_user_id' => $ownerId,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('customers')->whereNull('store_id')->update(['store_id' => $storeId]);
                DB::table('users')
                    ->where('id', $ownerId)
                    ->whereNull('username')
                    ->update(['username' => Str::lower($storeName)]);
            }

            DB::table('users')
                ->whereNull('username')
                ->orderBy('id')
                ->chunkById(100, function ($users): void {
                    foreach ($users as $user) {
                        DB::table('users')->where('id', $user->id)->update(['username' => 'user-'.$user->id]);
                    }
                });

            $this->assertDataPreserved($snapshotBefore);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: store assignments and usernames are removed when
     * the earlier store and user migrations are rolled back, and no customer
     * or financial row is modified.
     */
    public function down(): void
    {
        //
    }

    /**
     * @return array{customers: int, ledger_entries: int, payments: int, credit_total: string, payment_total: string}
     */
    private function financialSnapshot(): array
    {
        return [
            'customers' => DB::table('customers')->count(),
            'ledger_entries' => DB::table('ledger_entries')->count(),
            'payments' => DB::table('payments')->count(),
            'credit_total' => (string) DB::table('ledger_entries')->sum('amount'),
            'payment_total' => (string) DB::table('payments')->sum('amount'),
        ];
    }

    /**
     * @param  array{customers: int, ledger_entries: int, payments: int, credit_total: string, payment_total: string}  $snapshotBefore
     */
    private function assertDataPreserved(array $snapshotBefore): void
    {
        $snapshotAfter = $this->financialSnapshot();

        if ($snapshotAfter !== $snapshotBefore) {
            throw new RuntimeException('Store backfill aborted: record counts or financial totals changed during migration.');
        }

        $unassignedCustomerCount = DB::table('customers')->whereNull('store_id')->count();

        if ($unassignedCustomerCount > 0) {
            throw new RuntimeException("Store backfill aborted: {$unassignedCustomerCount} customers have no store.");
        }
    }
};
