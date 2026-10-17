<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of what customers owe, shared by the owner ledger, the
 * customer portal, the owner dashboard, and the overdue page. Read-only: it
 * never creates, changes, or assesses anything.
 *
 * Every figure comes from {@see entryBalances()}: each ledger entry with the
 * payments allocated to it, split by payment kind. Payments are allocated to a
 * specific entry (oldest first), so money allocated to a `credit` entry paid
 * principal and money allocated to a `late_penalty` entry paid a late fee.
 *
 * - original principal: the amounts credited
 * - principal paid: everything allocated to credits (cash and Suki Points)
 * - outstanding principal: credit amounts minus their allocations
 * - overdue principal: outstanding principal on credits past their deadline
 * - penalties assessed: late fee amounts
 * - penalties paid: cash and Suki Points allocated to late fees
 * - penalties waived: the owner's non-cash waiver adjustments
 * - outstanding penalties: late fee amounts minus their allocations
 * - total amount due: outstanding principal + outstanding penalties, which is
 *   the same as LedgerService's outstanding balance
 * - cash received: cash payments only; Suki Points and waivers are non-cash
 */
class FinancialSummaryService
{
    /**
     * Column added by {@see joinTotalDue()} for ordering customer lists.
     */
    public const TOTAL_DUE_SORT_COLUMN = 'total_due_for_sort';

    /**
     * Peso totals returned for every scope.
     *
     * @var list<string>
     */
    private const AMOUNT_FIELDS = [
        'original_principal',
        'principal_paid',
        'principal_outstanding',
        'overdue_principal',
        'penalties_assessed',
        'penalties_paid',
        'penalties_waived',
        'penalties_outstanding',
        'cash_received',
        'points_redeemed_amount',
    ];

    public function __construct(private readonly LatePenaltyService $latePenaltyService) {}

    public function today(): CarbonImmutable
    {
        return $this->latePenaltyService->today();
    }

    /**
     * Totals for one customer plus the unpaid amount of each of their entries.
     *
     * @return array{totals: array<string, mixed>, entry_remaining_cents: array<int, int>}
     */
    public function forCustomer(Customer $customer): array
    {
        return [
            'totals' => $this->totalsForCustomers([$customer->getKey()])[$customer->getKey()],
            'entry_remaining_cents' => $this->remainingCentsByEntry(
                $this->entryBalances()->where('ledger_entries.customer_id', $customer->getKey()),
            ),
        ];
    }

    /**
     * Totals keyed by customer id; customers without entries get zero totals.
     *
     * @param  iterable<int>  $customerIds
     * @return array<int, array<string, mixed>>
     */
    public function totalsForCustomers(iterable $customerIds): array
    {
        $customerIds = collect($customerIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();

        if ($customerIds->isEmpty()) {
            return [];
        }

        $rows = $this->aggregate($this->entryBalances()->whereIn('ledger_entries.customer_id', $customerIds->all()))
            ->addSelect('customer_id')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        return $customerIds
            ->mapWithKeys(fn (int $id): array => [$id => $this->present($rows->get($id))])
            ->all();
    }

    /**
     * Store-wide totals and counts for the owner dashboard.
     *
     * @return array{
     *     totals: array<string, mixed>,
     *     overdue_account_count: int,
     *     customers_with_outstanding_penalties: int,
     *     overdue_credit_penalty_states: array{in_grace: int, eligible: int, applied: int, not_covered: int}
     * }
     */
    public function forStore(Store $store): array
    {
        $today = $this->today();
        $storeEntries = fn (): Builder => DB::query()->fromSub(
            $this->entryBalances()->where('customers.store_id', $store->getKey()),
            'store_entries',
        );

        $overdueCreditRemaining = $storeEntries()
            ->where('kind', LedgerEntry::KIND_CREDIT)
            ->where('remaining_amount', '>', 0)
            ->where('repayment_deadline', '<', $today->toDateString())
            ->pluck('remaining_amount', 'id');
        $penaltyStates = ['in_grace' => 0, 'eligible' => 0, 'applied' => 0, 'not_covered' => 0];

        LedgerEntry::query()
            ->with('latePenalty')
            ->whereKey($overdueCreditRemaining->keys()->all())
            ->get()
            ->each(function (LedgerEntry $credit) use ($overdueCreditRemaining, $today, &$penaltyStates): void {
                $state = $this->latePenaltyService->statusFor(
                    $credit,
                    Money::sumToCents($overdueCreditRemaining[$credit->getKey()]),
                    $credit->latePenalty,
                    $today,
                )['state'];

                $penaltyStates[match ($state) {
                    LatePenaltyService::STATE_IN_GRACE, LatePenaltyService::STATE_NOT_DUE => 'in_grace',
                    LatePenaltyService::STATE_ELIGIBLE => 'eligible',
                    LatePenaltyService::STATE_APPLIED, LatePenaltyService::STATE_WAIVED => 'applied',
                    default => 'not_covered',
                }]++;
            });

        return [
            'totals' => $this->present(
                $this->aggregate($this->entryBalances()->where('customers.store_id', $store->getKey()))->first(),
            ),
            'overdue_account_count' => $storeEntries()
                ->where('kind', LedgerEntry::KIND_CREDIT)
                ->where('remaining_amount', '>', 0)
                ->where('repayment_deadline', '<', $today->toDateString())
                ->distinct()
                ->count('customer_id'),
            'customers_with_outstanding_penalties' => $storeEntries()
                ->where('kind', LedgerEntry::KIND_LATE_PENALTY)
                ->where('remaining_amount', '>', 0)
                ->distinct()
                ->count('customer_id'),
            'overdue_credit_penalty_states' => $penaltyStates,
        ];
    }

    /**
     * Combined totals of the store's overdue accounts (customers with at least
     * one unpaid credit past its deadline), optionally narrowed by name.
     *
     * @return array<string, mixed>
     */
    public function totalsForOverdueAccounts(Store $store, string $search = ''): array
    {
        $overdueCustomerIds = DB::query()
            ->fromSub($this->entryBalances()->where('customers.store_id', $store->getKey()), 'store_entries')
            ->where('kind', LedgerEntry::KIND_CREDIT)
            ->where('remaining_amount', '>', 0)
            ->where('repayment_deadline', '<', $this->today()->toDateString())
            ->select('customer_id');

        return $this->present($this->aggregate(
            $this->entryBalances()
                ->where('customers.store_id', $store->getKey())
                ->whereIn('ledger_entries.customer_id', $overdueCustomerIds)
                ->when($search !== '', fn (Builder $query): Builder => $query
                    ->where('customers.full_name', 'like', '%'.$search.'%')),
        )->first());
    }

    /**
     * Join each customer's total amount due onto a customer query so lists can
     * be ordered by it ({@see TOTAL_DUE_SORT_COLUMN}). Customers without
     * entries sort as ₱0.00.
     *
     * @param  EloquentBuilder<Customer>  $customers
     */
    public function joinTotalDue(EloquentBuilder $customers, Store $store): void
    {
        $totalDueByCustomer = DB::query()
            ->fromSub($this->entryBalances()->where('customers.store_id', $store->getKey()), 'store_entries')
            ->select('customer_id')
            ->selectRaw('SUM(remaining_amount) AS total_due')
            ->groupBy('customer_id');

        $customers
            ->leftJoinSub($totalDueByCustomer, 'customer_total_due', 'customer_total_due.customer_id', '=', 'customers.id')
            ->selectRaw('COALESCE(customer_total_due.total_due, 0) AS '.self::TOTAL_DUE_SORT_COLUMN);
    }

    /**
     * Unpaid amount of each given entry, in cents.
     *
     * @param  list<int>  $entryIds
     * @return array<int, int>
     */
    public function remainingCentsForEntries(array $entryIds): array
    {
        return $entryIds === []
            ? []
            : $this->remainingCentsByEntry($this->entryBalances()->whereIn('ledger_entries.id', $entryIds));
    }

    /**
     * Limit an Eloquent ledger-entry query to credits that are past their
     * deadline and still unpaid: the app's definition of an overdue entry. A
     * late fee has no deadline, so it never makes an account overdue.
     *
     * @param  EloquentBuilder<LedgerEntry>|Relation  $query
     */
    public function whereUnpaidOverdueCredit(EloquentBuilder|Relation $query, ?CarbonImmutable $today = null): void
    {
        $query
            ->where('ledger_entries.kind', LedgerEntry::KIND_CREDIT)
            ->whereNotNull('ledger_entries.repayment_deadline')
            ->whereDate('ledger_entries.repayment_deadline', '<', ($today ?? $this->today())->toDateString());
        $this->whereUnpaid($query);
    }

    /**
     * Limit an Eloquent ledger-entry query to late fees that are still unpaid.
     *
     * @param  EloquentBuilder<LedgerEntry>|Relation  $query
     */
    public function whereOutstandingPenalty(EloquentBuilder|Relation $query): void
    {
        $query->where('ledger_entries.kind', LedgerEntry::KIND_LATE_PENALTY);
        $this->whereUnpaid($query);
    }

    /**
     * One row per ledger entry with its store and the payments allocated to
     * it, split by payment kind.
     */
    public function entryBalances(): Builder
    {
        $allocationsByEntry = DB::table('payments')
            ->select('ledger_entry_id')
            ->selectRaw('SUM(amount) AS allocated_total')
            ->selectRaw('SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS cash_paid', [Payment::KIND_CASH])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS points_redeemed_amount', [Payment::KIND_LOYALTY_REDEMPTION])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS waived_amount', [Payment::KIND_PENALTY_WAIVER])
            ->groupBy('ledger_entry_id');

        return DB::table('ledger_entries')
            ->join('customers', 'customers.id', '=', 'ledger_entries.customer_id')
            ->leftJoinSub($allocationsByEntry, 'allocations', 'allocations.ledger_entry_id', '=', 'ledger_entries.id')
            ->select(
                'ledger_entries.id',
                'ledger_entries.customer_id',
                'customers.store_id',
                'ledger_entries.kind',
                'ledger_entries.amount',
                'ledger_entries.repayment_deadline',
            )
            ->selectRaw('COALESCE(allocations.allocated_total, 0) AS allocated_total')
            ->selectRaw('COALESCE(allocations.cash_paid, 0) AS cash_paid')
            ->selectRaw('COALESCE(allocations.points_redeemed_amount, 0) AS points_redeemed_amount')
            ->selectRaw('COALESCE(allocations.waived_amount, 0) AS waived_amount')
            ->selectRaw(
                'CASE WHEN ledger_entries.amount > COALESCE(allocations.allocated_total, 0) THEN ledger_entries.amount - COALESCE(allocations.allocated_total, 0) ELSE 0 END AS remaining_amount',
            );
    }

    private function aggregate(Builder $entryBalances): Builder
    {
        $credit = LedgerEntry::KIND_CREDIT;
        $penalty = LedgerEntry::KIND_LATE_PENALTY;
        $today = $this->today()->toDateString();

        return DB::query()
            ->fromSub($entryBalances, 'entry_balances')
            ->selectRaw('SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS original_principal', [$credit])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN allocated_total ELSE 0 END) AS principal_paid', [$credit])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN remaining_amount ELSE 0 END) AS principal_outstanding', [$credit])
            ->selectRaw('SUM(CASE WHEN kind = ? AND repayment_deadline < ? THEN remaining_amount ELSE 0 END) AS overdue_principal', [$credit, $today])
            ->selectRaw('SUM(CASE WHEN kind = ? AND repayment_deadline < ? AND remaining_amount > 0 THEN 1 ELSE 0 END) AS overdue_entry_count', [$credit, $today])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS penalties_assessed', [$penalty])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN cash_paid + points_redeemed_amount ELSE 0 END) AS penalties_paid', [$penalty])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN waived_amount ELSE 0 END) AS penalties_waived', [$penalty])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN remaining_amount ELSE 0 END) AS penalties_outstanding', [$penalty])
            ->selectRaw('SUM(cash_paid) AS cash_received')
            ->selectRaw('SUM(points_redeemed_amount) AS points_redeemed_amount');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?object $row): array
    {
        $cents = [];

        foreach (self::AMOUNT_FIELDS as $field) {
            $cents[$field] = Money::sumToCents($row?->{$field} ?? 0);
        }

        $cents['total_due'] = $cents['principal_outstanding'] + $cents['penalties_outstanding'];

        return [
            ...array_map(fn (int $amountInCents): string => Money::fromCents($amountInCents), $cents),
            'cents' => $cents,
            'overdue_entry_count' => (int) ($row?->overdue_entry_count ?? 0),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function remainingCentsByEntry(Builder $entryBalances): array
    {
        return $entryBalances->get()
            ->mapWithKeys(fn (object $entry): array => [(int) $entry->id => Money::sumToCents($entry->remaining_amount)])
            ->all();
    }

    /**
     * @param  EloquentBuilder<LedgerEntry>|Relation  $query
     */
    private function whereUnpaid(EloquentBuilder|Relation $query): void
    {
        $query->whereRaw(
            'ledger_entries.amount > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.ledger_entry_id = ledger_entries.id), 0)',
        );
    }
}
