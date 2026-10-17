<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only feed of credits and payments across all stores.
 *
 * Each credit entry is one row. Payments are stored as one allocation row
 * per credit entry they settle, so allocations sharing a payment batch are
 * combined into the single payment the owner recorded. Filters are applied
 * inside each half of the union, before sorting and pagination.
 */
class TransactionMonitoringService
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_PAYMENT = 'payment';

    /** Suki Points redeemed as store credit: reduces debt but is not cash received. */
    public const TYPE_REDEMPTION = 'loyalty_redemption';

    public const TYPES = [self::TYPE_CREDIT, self::TYPE_PAYMENT, self::TYPE_REDEMPTION];

    /**
     * Allowed sort keys mapped to their columns.
     *
     * @var array<string, string>
     */
    public const SORT_COLUMNS = [
        'date' => 'transaction_date',
        'store' => 'store_name',
        'type' => 'transaction_type',
        'amount' => 'amount',
    ];

    public function __construct(private readonly PlatformMetricsService $platformMetrics) {}

    /**
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, type?: string|null, customer?: string|null}  $filters
     */
    public function paginate(array $filters, string $sort, string $direction, int $perPage = 25): LengthAwarePaginator
    {
        $query = DB::query()->fromSub($this->transactions($filters), 'transactions');

        $query->orderBy(self::SORT_COLUMNS[$sort], $direction);

        if ($sort === 'date') {
            $query->orderBy('recorded_at', $direction);
        } else {
            $query->orderBy('transaction_date', 'desc')->orderBy('recorded_at', 'desc');
        }

        $transactions = $query
            ->orderBy('transaction_type')
            ->orderBy('reference_id', $direction)
            ->paginate($perPage)
            ->withQueryString();

        $today = $this->platformMetrics->today()->toDateString();

        $transactions->getCollection()->transform(function (object $transaction) use ($today): object {
            $transaction->amount = Money::fromCents(Money::sumToCents($transaction->amount));
            $transaction->remaining_amount = $transaction->transaction_type === self::TYPE_CREDIT
                ? Money::fromCents(Money::sumToCents($transaction->remaining_amount))
                : null;
            $transaction->status = match (true) {
                $transaction->transaction_type !== self::TYPE_CREDIT => null,
                $transaction->remaining_amount === '0.00' => 'Paid',
                $transaction->repayment_deadline !== null
                    && substr((string) $transaction->repayment_deadline, 0, 10) < $today => 'Overdue',
                default => 'Unpaid',
            };

            return $transaction;
        });

        return $transactions;
    }

    /**
     * Totals for every transaction matching the filters, not just one page.
     *
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, type?: string|null, customer?: string|null}  $filters
     * @return array{transaction_count: int, credit_total: string, payment_total: string}
     */
    public function totals(array $filters): array
    {
        $totals = DB::query()
            ->fromSub($this->transactions($filters), 'transactions')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN transaction_type = ? THEN amount ELSE 0 END), 0) AS credit_total', [self::TYPE_CREDIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN transaction_type = ? THEN amount ELSE 0 END), 0) AS payment_total', [self::TYPE_PAYMENT])
            ->first();

        return [
            'transaction_count' => (int) $totals->transaction_count,
            'credit_total' => Money::fromCents(Money::sumToCents($totals->credit_total)),
            'payment_total' => Money::fromCents(Money::sumToCents($totals->payment_total)),
        ];
    }

    /**
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, type?: string|null, customer?: string|null}  $filters
     */
    private function transactions(array $filters): Builder
    {
        $type = $filters['type'] ?? null;

        if ($type === self::TYPE_CREDIT) {
            return $this->credits($filters);
        }

        if ($type === self::TYPE_PAYMENT || $type === self::TYPE_REDEMPTION) {
            return $this->payments($filters, $type);
        }

        return $this->credits($filters)
            ->unionAll($this->payments($filters, self::TYPE_PAYMENT))
            ->unionAll($this->payments($filters, self::TYPE_REDEMPTION));
    }

    /**
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, customer?: string|null}  $filters
     */
    private function credits(array $filters): Builder
    {
        $paymentsByEntry = DB::table('payments')
            ->select('ledger_entry_id')
            ->selectRaw('SUM(amount) AS total_paid')
            ->groupBy('ledger_entry_id');

        $credits = DB::table('ledger_entries')
            ->join('customers', 'customers.id', '=', 'ledger_entries.customer_id')
            ->join('stores', 'stores.id', '=', 'customers.store_id')
            ->leftJoinSub($paymentsByEntry, 'payments_by_entry', 'payments_by_entry.ledger_entry_id', '=', 'ledger_entries.id')
            ->selectRaw('? AS transaction_type', [self::TYPE_CREDIT])
            ->selectRaw('ledger_entries.id AS reference_id')
            ->selectRaw('ledger_entries.transaction_date AS transaction_date')
            ->selectRaw('ledger_entries.created_at AS recorded_at')
            ->selectRaw('stores.id AS store_id')
            ->selectRaw('stores.store_name AS store_name')
            ->selectRaw('customers.id AS customer_id')
            ->selectRaw('customers.full_name AS customer_name')
            ->selectRaw('ledger_entries.amount AS amount')
            ->selectRaw('ledger_entries.repayment_deadline AS repayment_deadline')
            ->selectRaw('ledger_entries.note AS note')
            ->selectRaw(
                'CASE WHEN ledger_entries.amount > COALESCE(payments_by_entry.total_paid, 0) THEN ledger_entries.amount - COALESCE(payments_by_entry.total_paid, 0) ELSE 0 END AS remaining_amount',
            );

        return $this->applySharedFilters($credits, $filters, 'ledger_entries.transaction_date');
    }

    /**
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, customer?: string|null}  $filters
     */
    private function payments(array $filters, string $type): Builder
    {
        $payments = DB::table('payments')
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->join('stores', 'stores.id', '=', 'customers.store_id')
            ->where('payments.kind', $type === self::TYPE_REDEMPTION ? Payment::KIND_LOYALTY_REDEMPTION : Payment::KIND_CASH)
            ->selectRaw('? AS transaction_type', [$type])
            ->selectRaw('MIN(payments.id) AS reference_id')
            ->selectRaw('MAX(payments.payment_date) AS transaction_date')
            ->selectRaw('MAX(payments.created_at) AS recorded_at')
            ->selectRaw('stores.id AS store_id')
            ->selectRaw('stores.store_name AS store_name')
            ->selectRaw('customers.id AS customer_id')
            ->selectRaw('customers.full_name AS customer_name')
            ->selectRaw('SUM(payments.amount) AS amount')
            ->selectRaw('NULL AS repayment_deadline')
            ->selectRaw('MAX(payments.note) AS note')
            ->selectRaw('NULL AS remaining_amount')
            ->groupByRaw('COALESCE(payments.payment_batch_id, CAST(payments.id AS CHAR)), stores.id, stores.store_name, customers.id, customers.full_name');

        return $this->applySharedFilters($payments, $filters, 'payments.payment_date');
    }

    /**
     * @param  array{from?: string|null, to?: string|null, store_id?: int|string|null, customer?: string|null}  $filters
     */
    private function applySharedFilters(Builder $query, array $filters, string $dateColumn): Builder
    {
        return $query
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where($dateColumn, '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query
                ->where($dateColumn, '<', CarbonImmutable::parse($to)->addDay()->toDateString()))
            ->when($filters['store_id'] ?? null, fn (Builder $query, int|string $storeId) => $query->where('stores.id', (int) $storeId))
            ->when($filters['customer'] ?? null, fn (Builder $query, string $customer) => $query
                ->where('customers.full_name', 'like', '%'.$customer.'%'));
    }
}
