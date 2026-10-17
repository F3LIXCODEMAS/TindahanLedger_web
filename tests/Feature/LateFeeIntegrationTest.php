<?php

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use App\Models\User;
use App\Services\CustomerAccessService;
use App\Services\FinancialSummaryService;
use App\Services\LatePenaltyService;
use App\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['app.timezone' => 'Asia/Manila']);
});

function lateFeeDay(string $date, int $hour = 9): void
{
    test()->travelTo(CarbonImmutable::parse($date, 'Asia/Manila')->setTime($hour, 0));
}

function lateFeeCashPayment(Customer $customer, string $amount): void
{
    app(LedgerService::class)->recordCustomerPayment($customer, ['amount' => $amount, 'idempotency_key' => (string) Str::uuid()]);
}

/**
 * On Oct 1 a store with a ₱10 / 3-day policy records four covered credits.
 * The daily run on Oct 14 adds fees to the two due Oct 10, and the store is
 * viewed on Oct 19:
 *  - Ana: ₱500 due Oct 10, fee added, ₱200 cash paid: ₱300 principal + ₱10 fee
 *  - Ben: ₱300 due Oct 15, grace ended Oct 18, fee not added yet (eligible)
 *  - Cora: ₱100 due Oct 17, in grace until Oct 20
 *  - Dino: ₱400 due Oct 10, fee added, principal paid in full: ₱10 fee only
 * Another store has a ₱999 overdue credit.
 *
 * @return array{store: Store, ana: Customer, ben: Customer, cora: Customer, dino: Customer}
 */
function lateFeeStore(): array
{
    lateFeeDay('2026-10-01');
    $store = Store::factory()->create();
    StorePenaltyPolicy::factory()->for($store)->create(['fee_cents' => 1000, 'grace_days' => 3, 'effective_from' => '2026-10-01']);
    $creditFor = function (string $name, string $amount, string $deadline) use ($store): Customer {
        $customer = Customer::factory()->for($store)->create(['full_name' => $name, 'credit_limit' => '5000.00']);
        app(LedgerService::class)->recordCredit($customer, [
            'amount' => $amount,
            'repayment_deadline' => $deadline,
            'penalty_policy_acknowledged' => true,
        ]);

        return $customer;
    };
    $customers = [
        'ana' => $creditFor('Ana Reyes', '500.00', '2026-10-10'),
        'ben' => $creditFor('Ben Cruz', '300.00', '2026-10-15'),
        'cora' => $creditFor('Cora Lim', '100.00', '2026-10-17'),
        'dino' => $creditFor('Dino Santos', '400.00', '2026-10-10'),
    ];
    app(LedgerService::class)->recordCredit(Customer::factory()->create(['credit_limit' => '5000.00']), [
        'amount' => '999.00',
        'repayment_deadline' => '2026-10-05',
    ]);

    lateFeeDay('2026-10-14', 0);
    app(LatePenaltyService::class)->applyDuePenalties();
    lateFeeDay('2026-10-15');
    lateFeeCashPayment($customers['ana'], '200.00');
    lateFeeCashPayment($customers['dino'], '400.00');
    lateFeeDay('2026-10-19');

    return ['store' => $store, ...$customers];
}

describe('financial summary', function () {
    it('splits principal and late fees and treats waivers as non-cash', function () {
        ['store' => $store, 'ana' => $ana] = lateFeeStore();
        lateFeeCashPayment($ana, '305.00');
        $pointsBeforeWaiver = $ana->sukiPoints()->value('points_balance');

        app(LatePenaltyService::class)->waive(
            $ana->ledgerEntries()->where('kind', LedgerEntry::KIND_LATE_PENALTY)->sole(),
            $store->owner,
            'Goodwill for a regular customer',
        );

        expect(app(FinancialSummaryService::class)->forCustomer($ana)['totals'])->toMatchArray([
            'original_principal' => '500.00',
            'principal_paid' => '500.00',
            'principal_outstanding' => '0.00',
            'penalties_assessed' => '10.00',
            'penalties_paid' => '5.00',
            'penalties_waived' => '5.00',
            'penalties_outstanding' => '0.00',
            'total_due' => '0.00',
            'cash_received' => '505.00',
        ]);
        expect($ana->sukiPoints()->value('points_balance'))->toBe($pointsBeforeWaiver);
        expect($ana->debtCycleCompletions()->count())->toBe(0);
    });

    it('keeps each customer total equal to the authoritative balance and the store total', function () {
        $scenario = lateFeeStore();
        $customers = collect($scenario)->except('store');
        $summaryService = app(FinancialSummaryService::class);
        $customerTotals = $customers->map(fn (Customer $customer): array => $summaryService->forCustomer($customer)['totals']);

        $customers->each(function (Customer $customer, string $key) use ($customerTotals): void {
            $totalDueInCents = $customerTotals[$key]['cents']['total_due'];

            expect($totalDueInCents)->toBe(app(LedgerService::class)->outstandingBalanceInCents($customer))
                ->and(app(LedgerService::class)->financialSummary($customer)['available_credit'])->toBe(Money::fromCents(500000 - $totalDueInCents))
                ->and($customer->fresh()->credit_limit)->toBe('5000.00');
        });
        expect($customerTotals->sum(fn (array $totals): int => $totals['cents']['total_due']))
            ->toBe($summaryService->forStore($scenario['store'])['totals']['cents']['total_due']);
    });

    it('counts a credit as overdue only after its deadline day', function () {
        ['cora' => $cora] = lateFeeStore();
        $overdueCount = fn (): int => app(FinancialSummaryService::class)->forCustomer($cora)['totals']['overdue_entry_count'];

        lateFeeDay('2026-10-17');
        expect($overdueCount())->toBe(0);

        lateFeeDay('2026-10-18');
        expect($overdueCount())->toBe(1);
    });
});

describe('owner dashboard', function () {
    it('aggregates principal, late fees and fee states for the owner store only, without assessing fees', function () {
        $scenario = lateFeeStore();

        $this->actingAs($scenario['store']->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('outstandingPrincipal', '700.00')
            ->assertViewHas('outstandingPenalties', '20.00')
            ->assertViewHas('totalAmountDue', '720.00')
            ->assertViewHas('overdueAccountCount', 3)
            ->assertViewHas('customersWithOutstandingPenalties', 2)
            ->assertViewHas('overdueCreditPenaltyStates', ['in_grace' => 1, 'eligible' => 1, 'applied' => 1, 'not_covered' => 0])
            ->assertSeeTextInOrder(['Outstanding principal', '₱700.00', 'Outstanding late fees', '₱20.00', 'Total amount due', '₱720.00']);

        $this->get(route('overdue-accounts.index'))->assertOk();
        $this->get(route('customers.show', $scenario['ben']))->assertOk();
        expect(LedgerEntry::query()->where('kind', LedgerEntry::KIND_LATE_PENALTY)->count())->toBe(2);
    });
});

describe('overdue page', function () {
    it('separates overdue principal, late fees and customers who owe only a fee', function () {
        $scenario = lateFeeStore();

        $response = $this->actingAs($scenario['store']->owner)
            ->get(route('overdue-accounts.index'))
            ->assertOk()
            ->assertSeeText('Unpaid late fees')
            ->assertSeeText('₱10.00 unpaid');

        expect($response->viewData('customers')->pluck('full_name')->all())->toBe(['Ana Reyes', 'Ben Cruz', 'Cora Lim'])
            ->and($response->viewData('overdueTotals'))->toMatchArray([
                'overdue_principal' => '700.00',
                'penalties_outstanding' => '10.00',
                'total_due' => '710.00',
            ])
            ->and($response->viewData('feeOnlyCustomers')->pluck('full_name')->all())->toBe(['Dino Santos'])
            ->and($response->viewData('customers')->getCollection()->flatMap->ledgerEntries->mapWithKeys(
                fn (LedgerEntry $credit): array => [$credit->amount => $credit->penalty_status['state']],
            )->all())->toBe([
                '500.00' => LatePenaltyService::STATE_APPLIED,
                '300.00' => LatePenaltyService::STATE_ELIGIBLE,
                '100.00' => LatePenaltyService::STATE_IN_GRACE,
            ]);
    });

    it('sorts by total amount due including late fees', function () {
        $scenario = lateFeeStore();
        lateFeeCashPayment($scenario['ana'], '5.00');

        $response = $this->actingAs($scenario['store']->owner)
            ->get(route('overdue-accounts.index', ['sort' => 'balance']))
            ->assertOk();

        expect($response->viewData('customers')->getCollection()->map(
            fn (Customer $customer): array => [$customer->full_name, $customer->financials['total_due'], $customer->financials['principal_outstanding']],
        )->all())->toBe([
            ['Ana Reyes', '305.00', '295.00'],
            ['Ben Cruz', '300.00', '300.00'],
            ['Cora Lim', '100.00', '100.00'],
        ]);
    });

    it('pages the fee-only list separately from the overdue list', function () {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->count(21)->create()->each(
            fn (Customer $customer): LedgerEntry => LedgerEntry::factory()->for($customer)->create([
                'kind' => LedgerEntry::KIND_LATE_PENALTY,
                'amount' => '10.00',
                'repayment_deadline' => null,
            ]),
        );
        $this->actingAs($store->owner);

        $firstPage = $this->get(route('overdue-accounts.index'))->assertOk();
        expect($firstPage->viewData('feeOnlyCustomers'))->toHaveCount(20)
            ->and($firstPage->viewData('feeOnlyCustomers')->total())->toBe(21);

        $secondPage = $this->get(route('overdue-accounts.index', ['fees_page' => 2]))->assertOk();
        expect($secondPage->viewData('feeOnlyCustomers'))->toHaveCount(1)
            ->and($secondPage->viewData('customers')->currentPage())->toBe(1);
    });

    it('applies the search to the overdue list, its totals and the fee-only list', function () {
        $scenario = lateFeeStore();
        $this->actingAs($scenario['store']->owner);

        $anaOnly = $this->get(route('overdue-accounts.index', ['search' => 'Ana']))->assertOk();
        expect($anaOnly->viewData('overdueTotals'))->toMatchArray(['principal_outstanding' => '300.00', 'penalties_outstanding' => '10.00', 'total_due' => '310.00'])
            ->and($anaOnly->viewData('feeOnlyCustomers'))->toBeEmpty();

        $dinoOnly = $this->get(route('overdue-accounts.index', ['search' => 'Dino']))->assertOk();
        expect($dinoOnly->viewData('customers')->total())->toBe(0)
            ->and($dinoOnly->viewData('overdueTotals')['total_due'])->toBe('0.00')
            ->and($dinoOnly->viewData('feeOnlyCustomers')->pluck('full_name')->all())->toBe(['Dino Santos']);
    });
});

describe('customer portal', function () {
    it('shows the customer the same figures as the owner ledger, without waiver controls', function () {
        ['store' => $store, 'ana' => $ana] = lateFeeStore();
        $ownerLedger = $this->actingAs($store->owner)->get(route('customers.show', $ana))->assertOk()->viewData('ledger');
        Auth::guard('web')->logout();

        $this->post(route('portal.access'), ['token' => app(CustomerAccessService::class)->issue($ana)->plainToken()]);
        $portal = $this->get(route('portal.dashboard'))->assertOk();

        expect($portal->viewData('ledger')['financials'])->toBe($ownerLedger['financials'])
            ->and($portal->viewData('ledger')['financials']['total_due'])->toBe('310.00');
        $portal->assertSeeText('How late fees work')
            ->assertSeeText('Late fee of ₱10.00 added on Oct 14, 2026.')
            ->assertSeeText('Unpaid · ₱10.00 left')
            ->assertDontSee('Reason for waiving')
            ->assertDontSee(route('customers.late-penalties.waive', [$ana, $ana->ledgerEntries()->where('kind', LedgerEntry::KIND_LATE_PENALTY)->sole()]));
    });

    it('explains where each credit stands under the late fee policy', function (string $customerKey, string $expectedText) {
        $customer = lateFeeStore()[$customerKey];

        $this->post(route('portal.access'), ['token' => app(CustomerAccessService::class)->issue($customer)->plainToken()]);

        $this->get(route('portal.dashboard'))->assertOk()->assertSeeText($expectedText);
    })->with([
        'grace ended, fee not added yet' => ['ben', 'The grace period ended Oct 18, 2026. A ₱10.00 late fee will be added.'],
        'still in grace' => ['cora', 'Grace period until Oct 20, 2026. A ₱10.00 late fee is added if still unpaid after that.'],
        'principal paid, fee unpaid' => ['dino', 'Your credits are paid. A late fee of ₱10.00 is still unpaid.'],
    ]);
});

describe('historical records', function () {
    it('keeps settled late fee history viewable and undeletable after suspension and owner deactivation', function () {
        ['store' => $store, 'dino' => $dino] = lateFeeStore();
        lateFeeCashPayment($dino, '10.00');
        $store->update(['status' => Store::STATUS_SUSPENDED]);

        $this->actingAs($store->owner)
            ->get(route('customers.show', $dino))
            ->assertOk()
            ->assertViewHas('ledger', fn (array $ledger): bool => $ledger['financials']['penalties_assessed'] === '10.00'
                && $ledger['financials']['penalties_paid'] === '10.00'
                && $ledger['financials']['total_due'] === '0.00'
                && ! $ledger['is_overdue']);
        $this->get(route('overdue-accounts.index'))
            ->assertViewHas('feeOnlyCustomers', fn ($customers): bool => $customers->isEmpty());

        $store->owner->update(['status' => User::STATUS_INACTIVE]);
        Auth::guard('web')->logout();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.stores.customers.show', [$store, $dino]))
            ->assertOk()
            ->assertSeeText('Late payment fee');

        $penalty = $dino->ledgerEntries()->where('kind', LedgerEntry::KIND_LATE_PENALTY)->sole();
        expect(fn () => $penalty->penaltyFor->delete())->toThrow(QueryException::class)
            ->and(fn () => $dino->delete())->toThrow(QueryException::class)
            ->and($penalty->fresh()->penaltyFor->customer->store_id)->toBe($store->id);
    });
});
