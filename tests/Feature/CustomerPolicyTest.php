<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('decides customer and ledger access by store ownership', function (Closure $makeUser, bool $isAllowed) {
    $customer = Customer::factory()->create();
    $ledgerEntry = LedgerEntry::factory()->for($customer)->create();
    $user = $makeUser($customer);

    foreach (['view', 'update'] as $ability) {
        $response = Gate::forUser($user)->inspect($ability, $customer);

        expect($response->allowed())->toBe($isAllowed);
        expect($response->status())->toBe($isAllowed ? null : 404);
    }

    expect(Gate::forUser($user)->allows('view', $ledgerEntry))->toBe($isAllowed);
})->with([
    'owner of the customer store' => [fn (Customer $customer) => $customer->store->owner, true],
    'owner of another store' => [fn () => User::factory()->withStore()->create(), false],
    'owner without a store' => [fn () => User::factory()->create(), false],
    'super admin' => [fn () => User::factory()->superAdmin()->create(), false],
]);
