<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Store;
use App\Models\SukiPoint;
use App\Models\SukiPointTransaction;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\LedgerService;
use App\Services\PlatformMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A customer of the given store owing ₱500.00 with 80 Suki Points.
 */
function customerWithPointsAndDebt(Store $store, int $points = 80, string $debt = '500.00'): Customer
{
    $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

    if ($debt !== '0.00') {
        app(LedgerService::class)->recordCredit($customer, ['amount' => $debt]);
    }

    SukiPoint::factory()->for($customer)->create(['points_balance' => $points]);

    return $customer;
}

function redeem(Customer $customer, mixed $points, ?string $key = null): array
{
    return ['points' => $points, 'idempotency_key' => $key ?? (string) Str::uuid()];
}

describe('viewing Suki Points', function () {
    it('shows the points, the conversion rate and a redeem form limited to what can be redeemed', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);

        $this->actingAs($store->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSeeText('80 points')
            ->assertSeeText('1 point = ₱1.00 of store credit')
            ->assertSee('max="80"', false)
            ->assertSee(route('customers.suki-redemptions.store', $customer), false);
    });

    it('explains why redemption is unavailable', function (Closure $makeCustomer, string $reason) {
        $store = Store::factory()->create();
        $customer = $makeCustomer($store);

        $this->actingAs($store->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSeeText($reason)
            ->assertDontSee(route('customers.suki-redemptions.store', $customer), false);
    })->with([
        'no points' => [fn (Store $store) => customerWithPointsAndDebt($store, 0), 'No points to redeem yet.'],
        'no balance' => [fn (Store $store) => customerWithPointsAndDebt($store, 80, '0.00'), 'This customer has no outstanding balance to reduce.'],
    ]);

    it('limits the redeemable points to the outstanding balance', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store, 80, '30.50');

        $this->actingAs($store->owner)
            ->get(route('customers.show', $customer))
            ->assertSee('max="30"', false);
    });
});

describe('redeeming points', function () {
    it('applies the points as a non-cash credit and deducts them exactly once', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);

        $this->actingAs($store->owner)
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, 50))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('status', '50 Suki Points redeemed for ₱50.00. New balance: ₱450.00.');

        $redemption = Payment::query()->sole();
        expect($redemption->kind)->toBe(Payment::KIND_LOYALTY_REDEMPTION);
        expect($redemption->amount)->toBe('50.00');
        expect($customer->sukiPoints()->first()->points_balance)->toBe(30);
        expect(app(LedgerService::class)->outstandingBalanceInCents($customer))->toBe(45000);
        expect(SukiPointTransaction::query()->sole())
            ->transaction_type->toBe(SukiPointTransaction::TYPE_REDEEMED)
            ->points_redeemed->toBe(50)
            ->points_awarded->toBe(0)
            ->recorded_by_user_id->toBe($store->owner_user_id);

        $this->get(route('customers.show', $customer))
            ->assertSeeText('Suki Points redemption')
            ->assertSeeText('50 points (not cash)')
            ->assertSeeText('₱450.00');
    });

    it('rejects invalid point amounts without changing anything', function (mixed $points) {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);

        $this->actingAs($store->owner)
            ->from(route('customers.show', $customer))
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, $points))
            ->assertSessionHasErrors('points');

        expect(Payment::query()->count())->toBe(0);
        expect($customer->sukiPoints()->first()->points_balance)->toBe(80);
    })->with(['zero' => [0], 'negative' => [-5], 'decimal' => ['1.5'], 'text' => ['ten'], 'missing' => [null]]);

    it('rejects a redemption the customer cannot afford or that exceeds the balance', function (int $points, string $debt, string $message) {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store, 80, $debt);

        $this->actingAs($store->owner)
            ->from(route('customers.show', $customer))
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, $points))
            ->assertSessionHasErrors(['points' => $message]);

        expect(Payment::query()->count())->toBe(0);
        expect($customer->sukiPoints()->first()->points_balance)->toBe(80);
    })->with([
        'more points than available' => [81, '500.00', 'This customer only has 80 Suki Points available.'],
        'more than the balance' => [60, '40.00', 'Redeeming 60 points (₱60.00) would exceed the outstanding balance of ₱40.00. The most that can be redeemed now is 40 points.'],
        'no balance' => [10, '0.00', 'This customer has no outstanding balance to reduce.'],
    ]);

    it('deducts only once when the same submission is sent twice', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        $request = redeem($customer, 20);

        $this->actingAs($store->owner)->post(route('customers.suki-redemptions.store', $customer), $request);
        $this->actingAs($store->owner)->post(route('customers.suki-redemptions.store', $customer), $request)
            ->assertRedirect(route('customers.show', $customer));

        expect(Payment::query()->count())->toBe(1);
        expect($customer->sukiPoints()->first()->points_balance)->toBe(60);
    });

    it('rolls back everything when recording the redemption fails', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        SukiPointTransaction::creating(fn () => throw new RuntimeException('points ledger unavailable'));

        $this->actingAs($store->owner)
            ->from(route('customers.show', $customer))
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, 30))
            ->assertSessionHasErrors(['points' => 'Unable to redeem the points. Please try again.']);

        expect(Payment::query()->count())->toBe(0);
        expect($customer->sukiPoints()->first()->points_balance)->toBe(80);
        expect(app(LedgerService::class)->outstandingBalanceInCents($customer))->toBe(50000);
    });
});

describe('access', function () {
    it('responds as not found for another store customer and changes nothing', function () {
        $customer = customerWithPointsAndDebt(Store::factory()->create());
        $otherOwner = User::factory()->withStore()->create();

        $this->actingAs($otherOwner)
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, 10))
            ->assertNotFound();

        expect($customer->sukiPoints()->first()->points_balance)->toBe(80);
    });

    it('forbids the super admin from redeeming', function () {
        $customer = customerWithPointsAndDebt(Store::factory()->create());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, 10))
            ->assertForbidden();
    });

    it('blocks redemption while the store is suspended', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        $store->forceFill(['status' => Store::STATUS_SUSPENDED])->save();

        $this->actingAs($store->owner)
            ->post(route('customers.suki-redemptions.store', $customer), redeem($customer, 10))
            ->assertRedirect(route('dashboard'));

        expect($customer->sukiPoints()->first()->points_balance)->toBe(80);
    });
});

describe('effect on the rest of the ledger', function () {
    it('never completes a debt cycle or earns points, and the next cash payoff still counts', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store, 80, '30.00');
        $ledgerService = app(LedgerService::class);

        $ledgerService->redeemSukiPoints($customer, 30, (string) Str::uuid(), $store->owner);

        $sukiPoints = $customer->sukiPoints()->first();
        expect($sukiPoints->completed_cycles)->toBe(0);
        expect($sukiPoints->current_cycle_count)->toBe(0);
        expect($sukiPoints->points_balance)->toBe(50);
        expect($sukiPoints->streak_repaid_cents)->toBe(0);

        $ledgerService->recordCredit($customer, ['amount' => '100.00']);
        $receipt = $ledgerService->recordCustomerPayment($customer, ['amount' => '100.00', 'idempotency_key' => (string) Str::uuid()]);

        expect($receipt['debt_cycle_completed'])->toBeTrue();
        expect($customer->sukiPoints()->first())
            ->completed_cycles->toBe(1)
            ->points_balance->toBe(51);
    });

    it('excludes redemptions from cash payments received', function () {
        config(['app.timezone' => 'Asia/Manila']);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00:00', 'Asia/Manila'));
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        $ledgerService = app(LedgerService::class);
        $ledgerService->redeemSukiPoints($customer, 40, (string) Str::uuid(), $store->owner);
        $ledgerService->recordCustomerPayment($customer, ['amount' => '100.00', 'idempotency_key' => (string) Str::uuid()]);

        $metrics = app(PlatformMetricsService::class)->overview(
            CarbonImmutable::parse('2026-10-01', 'Asia/Manila'),
            CarbonImmutable::parse('2026-10-17', 'Asia/Manila'),
        );

        expect($metrics['payments_received'])->toBe('100.00');
        expect($metrics['outstanding_balance'])->toBe('360.00');
    });

    it('lists redemptions separately from cash payments in admin transaction monitoring', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        $ledgerService = app(LedgerService::class);
        $ledgerService->redeemSukiPoints($customer, 40, (string) Str::uuid(), $store->owner);
        $ledgerService->recordCustomerPayment($customer, ['amount' => '100.00', 'idempotency_key' => (string) Str::uuid()]);
        $this->actingAs(User::factory()->superAdmin()->create());

        $typesFor = fn (?string $type) => $this->get(route('admin.transactions.index', array_filter(['type' => $type])))
            ->assertOk()
            ->viewData('transactions')
            ->getCollection()
            ->pluck('transaction_type')
            ->sort()
            ->values()
            ->all();

        expect($typesFor(null))->toBe(['credit', 'loyalty_redemption', 'payment']);
        expect($typesFor('payment'))->toBe(['payment']);
        expect($typesFor('loyalty_redemption'))->toBe(['loyalty_redemption']);
        $this->get(route('admin.transactions.index'))->assertViewHas('totals', fn (array $totals): bool => $totals['payment_total'] === '100.00');
    });

    it('shows a redemption as a reduction in the customer portal ledger', function () {
        $store = Store::factory()->create();
        $customer = customerWithPointsAndDebt($store);
        app(LedgerService::class)->redeemSukiPoints($customer, 25, (string) Str::uuid(), $store->owner);

        $transactions = app(CustomerLedgerService::class)->forCustomer($customer)['transactions'];

        expect($transactions->last())
            ->type->toBe('loyalty_redemption')
            ->points_redeemed->toBe(25)
            ->running_balance->toBe('475.00');
    });
});
