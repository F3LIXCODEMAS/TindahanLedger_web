<?php

use Illuminate\Support\Facades\DB;

/**
 * Relax the store and username NOT NULL constraints so rows shaped like the
 * original single-owner schema can be inserted, then return the backfill.
 */
function revertStoreBackfill(): object
{
    (require database_path('migrations/2026_10_16_132730_require_store_on_customers_and_username_on_users.php'))->down();

    return require database_path('migrations/2026_10_16_131936_backfill_store_for_existing_owner.php');
}

/**
 * @return array{customer_id: int, ledger_entry_id: int, payment_id: int}
 */
function insertLegacyCustomerWithHistory(string $contactNumber, string $creditAmount, string $paymentAmount): array
{
    $now = now();
    $customerId = DB::table('customers')->insertGetId([
        'full_name' => 'Legacy Customer '.$contactNumber,
        'contact_number' => $contactNumber,
        'residential_landmark' => 'Near the market',
        'credit_limit' => '10000.00',
        'current_balance' => '0.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ledgerEntryId = DB::table('ledger_entries')->insertGetId([
        'customer_id' => $customerId,
        'amount' => $creditAmount,
        'transaction_date' => $now->toDateString(),
        'running_balance' => $creditAmount,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $paymentId = DB::table('payments')->insertGetId([
        'customer_id' => $customerId,
        'ledger_entry_id' => $ledgerEntryId,
        'amount' => $paymentAmount,
        'payment_date' => $now->toDateString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['customer_id' => $customerId, 'ledger_entry_id' => $ledgerEntryId, 'payment_id' => $paymentId];
}

function insertLegacyOwner(string $name, string $email): int
{
    return DB::table('users')->insertGetId([
        'name' => $name,
        'email' => $email,
        'password' => 'hashed-password-placeholder',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('assigns existing customers to a store named after the saved store name', function () {
    $migration = revertStoreBackfill();
    $ownerId = insertLegacyOwner('Felix', 'felix@example.test');
    DB::table('store_email_settings')->insert([
        'user_id' => $ownerId,
        'store_name' => '  Mang   Arthur Store ',
        'sender_email' => 'store@example.test',
        'smtp_app_password' => encrypt('app-password-placeholder', false),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $first = insertLegacyCustomerWithHistory('+639171234567', '850.50', '300.25');
    $second = insertLegacyCustomerWithHistory('+639181234567', '1200.00', '1200.00');

    $migration->up();

    $store = DB::table('stores')->sole();
    expect($store->store_name)->toBe('Mang Arthur Store');
    expect($store->owner_user_id)->toBe($ownerId);
    expect($store->status)->toBe('active');
    expect(DB::table('users')->where('id', $ownerId)->value('username'))->toBe('mang arthur store');
    expect(DB::table('customers')->pluck('store_id')->unique()->all())->toBe([$store->id]);
    expect(DB::table('payments')->where('id', $first['payment_id'])->first())
        ->customer_id->toBe($first['customer_id'])
        ->ledger_entry_id->toBe($first['ledger_entry_id']);
    expect(DB::table('payments')->where('id', $second['payment_id'])->first())
        ->customer_id->toBe($second['customer_id'])
        ->ledger_entry_id->toBe($second['ledger_entry_id']);
    expect((float) DB::table('ledger_entries')->sum('amount'))->toBe(2050.50);
    expect((float) DB::table('payments')->sum('amount'))->toBe(1500.25);
});

it('names the store after the owner when no store name was saved', function () {
    $migration = revertStoreBackfill();
    $ownerId = insertLegacyOwner('Felix', 'felix@example.test');
    insertLegacyCustomerWithHistory('+639171234567', '100.00', '50.00');

    $migration->up();

    expect(DB::table('stores')->sole()->store_name)->toBe("Felix's Store");
    expect(DB::table('users')->where('id', $ownerId)->value('username'))->toBe("felix's store");
});

it('stops without changing data when existing customers have no identifiable owner', function () {
    $migration = revertStoreBackfill();
    insertLegacyOwner('Felix', 'felix@example.test');
    insertLegacyOwner('Second Account', 'second@example.test');
    $legacy = insertLegacyCustomerWithHistory('+639171234567', '100.00', '50.00');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'expected exactly one existing owner account, found 2');

    expect(DB::table('stores')->count())->toBe(0);
    expect(DB::table('customers')->where('id', $legacy['customer_id'])->value('store_id'))->toBeNull();
    expect(DB::table('users')->whereNotNull('username')->count())->toBe(0);
});
