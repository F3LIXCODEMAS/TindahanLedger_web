<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use App\Models\User;
use App\Services\LatePenaltyService;
use App\Services\LedgerService;
use App\Services\PlatformMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['app.timezone' => 'Asia/Manila']);
});

function manilaDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
}

/**
 * A store with an active ₱10 / 3-day policy and a customer with one ₱500
 * credit due Oct 10, recorded Oct 1 with the policy acknowledged.
 *
 * @return array{store: Store, customer: Customer, credit: LedgerEntry}
 */
function creditUnderPolicy(int $feeCents = 1000, int $graceDays = 3, string $amount = '500.00'): array
{
    test()->travelTo(manilaDay('2026-10-01')->setTime(9, 0));
    $store = Store::factory()->create();
    StorePenaltyPolicy::factory()->for($store)->create([
        'fee_cents' => $feeCents,
        'grace_days' => $graceDays,
        'effective_from' => '2026-10-01',
    ]);
    $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
    $credit = app(LedgerService::class)->recordCredit($customer, [
        'amount' => $amount,
        'repayment_deadline' => '2026-10-10',
        'penalty_policy_acknowledged' => true,
    ]);

    return compact('store', 'customer', 'credit');
}

function pay(Customer $customer, string $amount): array
{
    return app(LedgerService::class)->recordCustomerPayment($customer, ['amount' => $amount, 'idempotency_key' => (string) Str::uuid()]);
}

function runPenalties(string $date): array
{
    test()->travelTo(manilaDay($date)->setTime(0, 30));

    return app(LatePenaltyService::class)->applyDuePenalties();
}

function penaltiesFor(Customer $customer)
{
    return $customer->ledgerEntries()->where('kind', LedgerEntry::KIND_LATE_PENALTY)->get();
}

describe('policy settings', function () {
    it('saves the policy for the owner store and raises the version on each change', function () {
        $owner = User::factory()->withStore()->create();
        $payload = ['is_enabled' => '1', 'fee' => '10.00', 'grace_days' => '3', 'effective_from' => '2026-10-17', 'disclosure_text' => 'Pay on time please.'];

        $this->actingAs($owner)->put(route('late-penalties.update'), $payload)->assertRedirect(route('late-penalties.edit'));
        $this->put(route('late-penalties.update'), [...$payload, 'fee' => '15.50']);

        expect($owner->store->penaltyPolicy()->sole())
            ->fee_cents->toBe(1550)
            ->grace_days->toBe(3)
            ->version->toBe(2)
            ->updated_by_user_id->toBe($owner->id);
    });

    it('rejects invalid policy values', function (array $override, string $field) {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)
            ->from(route('late-penalties.edit'))
            ->put(route('late-penalties.update'), [...['is_enabled' => '1', 'fee' => '10.00', 'grace_days' => '3', 'effective_from' => '2026-10-17'], ...$override])
            ->assertSessionHasErrors($field);

        expect(StorePenaltyPolicy::query()->count())->toBe(0);
    })->with([
        'zero fee' => [['fee' => '0'], 'fee'],
        'negative fee' => [['fee' => '-5'], 'fee'],
        'fee above the approved maximum' => [['fee' => '100.01'], 'fee'],
        'three decimals' => [['fee' => '10.005'], 'fee'],
        'negative grace' => [['grace_days' => '-1'], 'grace_days'],
        'grace above maximum' => [['grace_days' => '31'], 'grace_days'],
        'bad date' => [['effective_from' => '17/10/2026'], 'effective_from'],
    ]);

    it('forbids the super admin and redirects guests', function () {
        $this->put(route('late-penalties.update'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('late-penalties.edit'))->assertForbidden();
    });
});

describe('disclosure when recording a credit', function () {
    it('shows the policy and requires confirmation when a deadline is set', function () {
        $store = Store::factory()->create();
        StorePenaltyPolicy::factory()->for($store)->create(['fee_cents' => 1000, 'grace_days' => 3]);
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        $credit = fn (array $extra = []) => ['customer_id' => $customer->id, 'amount' => '100.00', 'repayment_deadline' => now()->addWeek()->toDateString(), 'idempotency_key' => (string) Str::uuid(), ...$extra];

        $this->actingAs($store->owner)->get(route('credits.create'))->assertSeeText('a one-time late fee of ₱10.00');
        $this->from(route('credits.create'))->post(route('credits.store'), $credit())
            ->assertSessionHasErrors(['penalty_policy_acknowledged' => 'Confirm that the customer was told about the late payment policy.']);
        $this->post(route('credits.store'), $credit(['penalty_policy_acknowledged' => '1']))->assertSessionHasNoErrors();

        expect(LedgerEntry::query()->sole()->penalty_policy_snapshot)
            ->toMatchArray(['version' => 1, 'fee_cents' => 1000, 'grace_days' => 3]);
    });

    it('does not cover credits without a deadline, before the policy starts, or when it is off', function (Closure $makePolicy, ?string $deadline) {
        $store = Store::factory()->create();
        $makePolicy($store);
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);

        $this->actingAs($store->owner)->post(route('credits.store'), [
            'customer_id' => $customer->id,
            'amount' => '100.00',
            'repayment_deadline' => $deadline,
            'penalty_policy_acknowledged' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        expect(LedgerEntry::query()->sole()->penalty_policy_snapshot)->toBeNull();
    })->with([
        'no deadline' => [fn (Store $store) => StorePenaltyPolicy::factory()->for($store)->create(), null],
        'policy starts tomorrow' => [fn (Store $store) => StorePenaltyPolicy::factory()->for($store)->create(['effective_from' => now()->addDay()->toDateString()]), '2099-01-01'],
        'policy turned off' => [fn (Store $store) => StorePenaltyPolicy::factory()->disabled()->for($store)->create(), '2099-01-01'],
    ]);
});

describe('applying penalties', function () {
    it('applies the fee only after the last grace day', function () {
        ['customer' => $customer, 'credit' => $credit] = creditUnderPolicy();

        runPenalties('2026-10-13');
        expect(penaltiesFor($customer))->toHaveCount(0);

        runPenalties('2026-10-14');
        $penalty = penaltiesFor($customer)->sole();
        expect($penalty->amount)->toBe('10.00');
        expect($penalty->penalty_for_entry_id)->toBe($credit->id);
        expect($penalty->repayment_deadline)->toBeNull();
        expect(app(LedgerService::class)->outstandingBalanceInCents($customer))->toBe(51000);
        expect($credit->fresh()->amount)->toBe('500.00');
    });

    it('does not charge a credit paid before the deadline or during the grace period', function (string $paidOn) {
        ['customer' => $customer] = creditUnderPolicy();
        test()->travelTo(manilaDay($paidOn)->setTime(10, 0));
        pay($customer, '500.00');

        runPenalties('2026-10-20');

        expect(penaltiesFor($customer))->toHaveCount(0);
    })->with(['before the deadline' => ['2026-10-09'], 'last grace day' => ['2026-10-13']]);

    it('charges a partly paid credit once, and further payments or runs never add another fee', function () {
        ['customer' => $customer] = creditUnderPolicy();
        test()->travelTo(manilaDay('2026-10-08'));
        pay($customer, '300.00');

        runPenalties('2026-10-14');
        test()->travelTo(manilaDay('2026-10-15'));
        pay($customer, '50.00');
        $secondRun = runPenalties('2026-10-16');
        runPenalties('2026-10-30');

        expect(penaltiesFor($customer))->toHaveCount(1);
        expect($secondRun['applied'])->toBe(0);
        expect(app(LedgerService::class)->outstandingBalanceInCents($customer))->toBe(16000);
    });

    it('keeps the penalty record when the rest is paid, and pays principal before the fee', function () {
        ['customer' => $customer, 'credit' => $credit] = creditUnderPolicy();
        runPenalties('2026-10-14');
        $penalty = penaltiesFor($customer)->sole();

        pay($customer, '500.00');
        expect(app(LatePenaltyService::class)->remainingCents($credit->fresh()))->toBe(0);
        expect(app(LatePenaltyService::class)->remainingCents($penalty))->toBe(1000);

        $receipt = pay($customer, '10.00');
        expect($receipt['debt_cycle_completed'])->toBeTrue();
        expect($penalty->fresh())->not->toBeNull();
    });

    it('never applies a policy to credits recorded before it, and later changes do not alter accepted credits', function () {
        test()->travelTo(manilaDay('2026-10-01'));
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['credit_limit' => '5000.00']);
        app(LedgerService::class)->recordCredit($customer, ['amount' => '100.00', 'repayment_deadline' => '2026-10-05']);
        $policy = StorePenaltyPolicy::factory()->for($store)->create(['fee_cents' => 1000, 'grace_days' => 3, 'effective_from' => '2026-10-01']);
        app(LedgerService::class)->recordCredit($customer, ['amount' => '100.00', 'repayment_deadline' => '2026-10-05', 'penalty_policy_acknowledged' => true]);
        $policy->forceFill(['fee_cents' => 5000, 'version' => 2])->save();

        runPenalties('2026-10-20');

        expect(penaltiesFor($customer)->pluck('amount')->all())->toBe(['10.00']);
    });

    it('caps a stored fee at the approved maximum', function () {
        ['customer' => $customer] = creditUnderPolicy(feeCents: 50000);
        config(['penalties.max_fee_cents' => 10000]);

        runPenalties('2026-10-14');

        expect(penaltiesFor($customer)->sole()->amount)->toBe('100.00');
    });

    it('skips a suspended store and applies the fee once it is reactivated', function () {
        ['store' => $store, 'customer' => $customer] = creditUnderPolicy();
        $store->forceFill(['status' => Store::STATUS_SUSPENDED])->save();

        expect(runPenalties('2026-10-14')['applied'])->toBe(0);

        $store->forceFill(['status' => Store::STATUS_ACTIVE])->save();
        expect(runPenalties('2026-10-15')['applied'])->toBe(1);
    });

    it('allows only one penalty per credit at the database level', function () {
        ['credit' => $credit] = creditUnderPolicy();
        runPenalties('2026-10-14');

        expect(fn () => LedgerEntry::factory()->for($credit->customer)->create([
            'kind' => LedgerEntry::KIND_LATE_PENALTY,
            'penalty_for_entry_id' => $credit->id,
        ]))->toThrow(QueryException::class);
    });

    it('treats a second processor applying the same penalty as a skip, not an error', function () {
        ['customer' => $customer, 'credit' => $credit] = creditUnderPolicy();
        $service = app(LatePenaltyService::class);
        test()->travelTo(manilaDay('2026-10-14'));

        expect($service->applyTo($credit))->toBeTrue();
        expect($service->applyTo($credit))->toBeFalse();
        expect(penaltiesFor($customer))->toHaveCount(1);
    });

    it('rolls back when saving the penalty fails', function () {
        ['customer' => $customer] = creditUnderPolicy();
        Customer::updating(fn () => throw new RuntimeException('balance update failed'));

        $summary = runPenalties('2026-10-14');

        expect($summary['failed'])->toBe(1);
        expect(penaltiesFor($customer))->toHaveCount(0);
    });

    it('is not cash received, earns no points, and counts toward the credit limit', function () {
        ['customer' => $customer] = creditUnderPolicy(amount: '4995.00');
        runPenalties('2026-10-14');

        $metrics = app(PlatformMetricsService::class)->overview(manilaDay('2026-10-01'), manilaDay('2026-10-14'));
        expect($metrics['payments_received'])->toBe('0.00');
        expect($customer->sukiPoints()->first()?->points_balance ?? 0)->toBe(0);
        expect(app(LedgerService::class)->financialSummary($customer)['available_credit'])->toBe('0.00');
    });

    it('runs from the artisan command', function () {
        creditUnderPolicy();
        test()->travelTo(manilaDay('2026-10-14')->setTime(0, 30));

        $this->artisan('penalties:apply-due')
            ->expectsOutput('Checked 1 overdue credits: 1 fees applied, 0 skipped, 0 failed.')
            ->assertSuccessful();
    });
});

describe('ledger and waivers', function () {
    it('shows the fee separately with principal and fee balances', function () {
        ['store' => $store, 'customer' => $customer] = creditUnderPolicy();
        runPenalties('2026-10-14');

        $this->actingAs($store->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSeeText('Late payment fee')
            ->assertSeeText('Principal ₱500.00 + late fees ₱10.00')
            ->assertSeeText('Waive fee');
    });

    it('lets the owner waive an unpaid fee with a reason, recorded as a non-cash adjustment', function () {
        ['store' => $store, 'customer' => $customer] = creditUnderPolicy();
        runPenalties('2026-10-14');
        $penalty = penaltiesFor($customer)->sole();

        $this->actingAs($store->owner)
            ->post(route('customers.late-penalties.waive', [$customer, $penalty]), ['reason' => 'Paid on time, recorded late'])
            ->assertRedirect(route('customers.show', $customer));

        expect($penalty->fresh())
            ->penalty_waiver_reason->toBe('Paid on time, recorded late')
            ->penalty_waived_by_user_id->toBe($store->owner_user_id);
        expect(Payment::query()->where('kind', Payment::KIND_PENALTY_WAIVER)->sole()->amount)->toBe('10.00');
        expect(app(LedgerService::class)->outstandingBalanceInCents($customer))->toBe(50000);
        expect($customer->sukiPoints()->first()?->points_balance ?? 0)->toBe(0);

        $this->from(route('customers.show', $customer))
            ->post(route('customers.late-penalties.waive', [$customer, $penalty]), ['reason' => 'Again'])
            ->assertSessionHasErrors(['reason' => 'This penalty has already been paid or waived.']);
    });

    it('requires a reason and refuses other stores and non-penalty entries', function () {
        ['store' => $store, 'customer' => $customer, 'credit' => $credit] = creditUnderPolicy();
        runPenalties('2026-10-14');
        $penalty = penaltiesFor($customer)->sole();

        $this->actingAs($store->owner)
            ->from(route('customers.show', $customer))
            ->post(route('customers.late-penalties.waive', [$customer, $penalty]), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->post(route('customers.late-penalties.waive', [$customer, $credit]), ['reason' => 'Not a penalty'])->assertNotFound();
        $this->actingAs(User::factory()->withStore()->create())
            ->post(route('customers.late-penalties.waive', [$customer, $penalty]), ['reason' => 'Cross-store'])
            ->assertNotFound();

        expect($penalty->fresh()->penalty_waived_at)->toBeNull();
    });
});
