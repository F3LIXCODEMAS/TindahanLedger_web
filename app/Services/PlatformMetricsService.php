<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide figures for the Super Admin.
 *
 * Balances follow the store ledger's rules: an entry's remaining amount is its
 * credit minus the payments applied to it (never below zero), outstanding debt
 * is the sum of remaining amounts, and an entry is overdue only when its
 * repayment deadline is before today and it still has a remaining amount.
 */
class PlatformMetricsService
{
    /**
     * @return array{
     *     total_stores: int,
     *     active_stores: int,
     *     suspended_stores: int,
     *     total_customers: int,
     *     outstanding_balance: string,
     *     payments_received: string,
     *     overdue_balance: string,
     *     stores_with_overdue: int
     * }
     */
    public function overview(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $storeCounts = Store::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total_stores')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS active_stores', [Store::STATUS_ACTIVE])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS suspended_stores', [Store::STATUS_SUSPENDED])
            ->first();

        $balanceTotals = DB::query()
            ->fromSub($this->storeBalances(), 'store_balances')
            ->selectRaw('COALESCE(SUM(outstanding_balance), 0) AS outstanding_balance')
            ->selectRaw('COALESCE(SUM(overdue_balance), 0) AS overdue_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN overdue_balance > 0 THEN 1 ELSE 0 END), 0) AS stores_with_overdue')
            ->first();

        $paymentsReceived = Payment::query()
            ->whereBetween('payment_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('amount');

        return [
            'total_stores' => (int) $storeCounts->total_stores,
            'active_stores' => (int) $storeCounts->active_stores,
            'suspended_stores' => (int) $storeCounts->suspended_stores,
            'total_customers' => Customer::query()->count(),
            'outstanding_balance' => Money::fromCents(Money::sumToCents($balanceTotals->outstanding_balance)),
            'payments_received' => Money::fromCents(Money::sumToCents($paymentsReceived)),
            'overdue_balance' => Money::fromCents(Money::sumToCents($balanceTotals->overdue_balance)),
            'stores_with_overdue' => (int) $balanceTotals->stores_with_overdue,
        ];
    }

    /**
     * Stores with their current customer count, outstanding balance, and
     * overdue balance as sortable columns.
     *
     * @return EloquentBuilder<Store>
     */
    public function storesWithBalances(): EloquentBuilder
    {
        $customerCounts = DB::table('customers')
            ->select('store_id')
            ->selectRaw('COUNT(*) AS customer_count')
            ->groupBy('store_id');

        return Store::query()
            ->select('stores.*')
            ->selectRaw('COALESCE(customer_counts.customer_count, 0) AS customer_count')
            ->selectRaw('COALESCE(store_balances.outstanding_balance, 0) AS outstanding_balance')
            ->selectRaw('COALESCE(store_balances.overdue_balance, 0) AS overdue_balance')
            ->leftJoinSub($customerCounts, 'customer_counts', 'customer_counts.store_id', '=', 'stores.id')
            ->leftJoinSub($this->storeBalances(), 'store_balances', 'store_balances.store_id', '=', 'stores.id');
    }

    /**
     * Current outstanding and overdue balance per store.
     */
    public function storeBalances(): Builder
    {
        $today = $this->today()->toDateString();

        return DB::query()
            ->fromSub($this->remainingBalancesByEntry(), 'entry_balances')
            ->select('store_id')
            ->selectRaw('SUM(remaining_amount) AS outstanding_balance')
            ->selectRaw(
                'SUM(CASE WHEN repayment_deadline < ? AND remaining_amount > 0 THEN remaining_amount ELSE 0 END) AS overdue_balance',
                [$today],
            )
            ->groupBy('store_id');
    }

    /**
     * One row per ledger entry with its store and remaining unpaid amount.
     */
    public function remainingBalancesByEntry(): Builder
    {
        $paymentsByEntry = DB::table('payments')
            ->select('ledger_entry_id')
            ->selectRaw('SUM(amount) AS total_paid')
            ->groupBy('ledger_entry_id');

        return DB::table('ledger_entries')
            ->join('customers', 'customers.id', '=', 'ledger_entries.customer_id')
            ->leftJoinSub($paymentsByEntry, 'payments_by_entry', 'payments_by_entry.ledger_entry_id', '=', 'ledger_entries.id')
            ->select('ledger_entries.id', 'ledger_entries.customer_id', 'customers.store_id', 'ledger_entries.repayment_deadline')
            ->selectRaw(
                'CASE WHEN ledger_entries.amount > COALESCE(payments_by_entry.total_paid, 0) THEN ledger_entries.amount - COALESCE(payments_by_entry.total_paid, 0) ELSE 0 END AS remaining_amount',
            );
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
    }
}
