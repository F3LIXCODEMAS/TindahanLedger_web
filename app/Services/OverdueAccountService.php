<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Overdue accounts are customers with at least one unpaid credit past its
 * deadline. Every amount comes from FinancialSummaryService, so the figures
 * match the customer ledger and dashboard.
 */
class OverdueAccountService
{
    public function __construct(
        private readonly LatePenaltyService $latePenaltyService,
        private readonly FinancialSummaryService $financialSummaryService,
    ) {}

    public function paginate(Store $store, string $search, string $sort): LengthAwarePaginator
    {
        $timezone = (string) config('app.timezone');
        $today = $this->financialSummaryService->today();
        $isUnpaidAndOverdue = fn (Builder|Relation $query): mixed => $this->financialSummaryService
            ->whereUnpaidOverdueCredit($query, $today);

        $earliestOverdueDeadline = LedgerEntry::query()
            ->selectRaw('MIN(ledger_entries.repayment_deadline)')
            ->whereColumn('ledger_entries.customer_id', 'customers.id');
        $isUnpaidAndOverdue($earliestOverdueDeadline);

        $customersQuery = Customer::query()
            ->select('customers.*')
            ->where('customers.store_id', $store->getKey())
            ->tap(fn (Builder $query): mixed => $this->financialSummaryService->joinTotalDue($query, $store))
            ->selectSub($earliestOverdueDeadline, 'earliest_overdue_deadline')
            ->with([
                'ledgerEntries' => function (Relation $query) use ($isUnpaidAndOverdue): void {
                    $isUnpaidAndOverdue($query);
                    $query
                        ->with([
                            'latePenalty',
                            'purchase.items',
                            'emailReminderLogs' => fn (Relation $reminders): Relation => $reminders
                                ->orderByDesc('scheduled_reminder_date')
                                ->orderByDesc('id'),
                        ])
                        ->orderBy('repayment_deadline')
                        ->orderBy('transaction_date')
                        ->orderBy('id');
                },
            ])
            ->whereHas('ledgerEntries', $isUnpaidAndOverdue)
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where('full_name', 'like', '%'.$search.'%'));

        if ($sort === 'balance') {
            $customersQuery
                ->orderByDesc(FinancialSummaryService::TOTAL_DUE_SORT_COLUMN)
                ->orderBy('earliest_overdue_deadline')
                ->orderBy('full_name');
        } else {
            $customersQuery
                ->orderBy('earliest_overdue_deadline')
                ->orderByDesc(FinancialSummaryService::TOTAL_DUE_SORT_COLUMN)
                ->orderBy('full_name');
        }

        $customers = $customersQuery
            ->orderBy('customers.id')
            ->paginate(20)
            ->withQueryString();

        $totalsByCustomer = $this->financialSummaryService->totalsForCustomers($customers->getCollection()->modelKeys());
        $remainingCentsByEntry = $this->financialSummaryService->remainingCentsForEntries(
            $customers->getCollection()->flatMap->ledgerEntries->pluck('id')->all(),
        );

        $customers->getCollection()->each(function (Customer $customer) use ($today, $timezone, $totalsByCustomer, $remainingCentsByEntry): void {
            $totals = $totalsByCustomer[$customer->getKey()];
            $customer->setAttribute('financials', $totals);
            $customer->setAttribute('outstanding_balance', $totals['total_due']);

            $overdueDays = 0;

            foreach ($customer->ledgerEntries as $entry) {
                $remainingAmountInCents = $remainingCentsByEntry[$entry->getKey()] ?? 0;
                $deadline = CarbonImmutable::parse($entry->repayment_deadline->toDateString(), $timezone)
                    ->startOfDay();
                $entryOverdueDays = (int) $deadline->diffInDays($today);

                $entry->setAttribute('unpaid_amount', Money::fromCents($remainingAmountInCents));
                $entry->setAttribute('days_overdue', $entryOverdueDays);
                $entry->setAttribute('penalty_status', $this->latePenaltyService->statusFor($entry, $remainingAmountInCents, $entry->latePenalty, $today));
                $overdueDays = max($overdueDays, $entryOverdueDays);
            }

            $customer->setAttribute('overdue_days', $overdueDays);
            $customer->setAttribute(
                'earliest_overdue_deadline',
                CarbonImmutable::parse((string) $customer->getAttribute('earliest_overdue_deadline'), $timezone)
                    ->startOfDay(),
            );
        });

        return $customers;
    }

    /**
     * Combined principal, late fees, and total due of all overdue accounts
     * matching the search, not just the current page.
     *
     * @return array<string, mixed>
     */
    public function totals(Store $store, string $search): array
    {
        return $this->financialSummaryService->totalsForOverdueAccounts($store, $search);
    }

    /**
     * Customers whose credits are no longer overdue but who still owe a late
     * fee. They are listed separately and are not counted as overdue accounts,
     * because a late fee has no deadline of its own. Paginated on its own
     * `fees_page` parameter so it pages independently of the overdue list.
     */
    public function customersWithOnlyUnpaidFees(Store $store, string $search): LengthAwarePaginator
    {
        $today = $this->financialSummaryService->today();
        $customers = Customer::query()
            ->where('store_id', $store->getKey())
            ->whereHas('ledgerEntries', fn (Builder $query): mixed => $this->financialSummaryService->whereOutstandingPenalty($query))
            ->whereDoesntHave('ledgerEntries', fn (Builder $query): mixed => $this->financialSummaryService->whereUnpaidOverdueCredit($query, $today))
            ->when($search !== '', fn (Builder $query): Builder => $query->where('full_name', 'like', '%'.$search.'%'))
            ->with(['ledgerEntries' => function (Relation $query): void {
                $this->financialSummaryService->whereOutstandingPenalty($query);
                $query->with('penaltyFor')->orderBy('transaction_date')->orderBy('id');
            }])
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(20, pageName: 'fees_page')
            ->withQueryString();

        $totalsByCustomer = $this->financialSummaryService->totalsForCustomers($customers->getCollection()->modelKeys());
        $remainingCentsByEntry = $this->financialSummaryService->remainingCentsForEntries(
            $customers->getCollection()->flatMap->ledgerEntries->pluck('id')->all(),
        );

        $customers->getCollection()->each(function (Customer $customer) use ($totalsByCustomer, $remainingCentsByEntry): void {
            $customer->setAttribute('financials', $totalsByCustomer[$customer->getKey()]);
            $customer->ledgerEntries->each(fn (LedgerEntry $penalty): LedgerEntry => $penalty->setAttribute(
                'unpaid_amount',
                Money::fromCents($remainingCentsByEntry[$penalty->getKey()] ?? 0),
            ));
        });

        return $customers;
    }
}
