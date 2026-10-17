<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate analytics for the Super Admin. Period figures count records
 * dated inside the selected range; balances are current as of today and use
 * the same remaining-amount and overdue rules as PlatformMetricsService.
 * Customer identities are never included in these aggregates.
 */
class PlatformAnalyticsService
{
    public const INTERVALS = ['day', 'week', 'month'];

    public const AGING_BUCKETS = [
        'current' => 'Not yet overdue',
        'overdue_1_7' => '1–7 days overdue',
        'overdue_8_30' => '8–30 days overdue',
        'overdue_over_30' => 'More than 30 days overdue',
    ];

    /**
     * Allowed store comparison sort keys mapped to their columns.
     *
     * @var array<string, string>
     */
    public const COMPARISON_SORT_COLUMNS = [
        'name' => 'stores.store_name',
        'credits' => 'period_credit_total',
        'payments' => 'period_payment_total',
        'outstanding' => 'outstanding_balance',
        'overdue' => 'overdue_balance',
        'customers' => 'customer_count',
        'activity' => 'last_activity_at',
    ];

    public function __construct(private readonly PlatformMetricsService $platformMetrics) {}

    /**
     * Credit and payment totals per day, week (starting Monday), or month,
     * with empty periods filled in. A payment split across several credits
     * counts as one payment.
     *
     * @return list<array{
     *     label: string,
     *     credit_amount: string,
     *     credit_cents: int,
     *     credit_count: int,
     *     payment_amount: string,
     *     payment_cents: int,
     *     payment_count: int
     * }>
     */
    public function trends(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $interval): array
    {
        $buckets = [];
        $cursor = $this->bucketStart($periodStart, $interval);

        while ($cursor->lessThanOrEqualTo($periodEnd)) {
            $buckets[$this->bucketKey($cursor, $interval)] = [
                'label' => $this->bucketLabel($cursor, $interval),
                'credit_cents' => 0,
                'credit_count' => 0,
                'payment_cents' => 0,
                'payment_count' => 0,
            ];
            $cursor = $cursor->add(1, $interval);
        }

        $dailyCredits = $this->dailyTotals(
            DB::table('ledger_entries')->where('kind', LedgerEntry::KIND_CREDIT),
            'transaction_date',
            'COUNT(*)',
            $periodStart,
            $periodEnd,
        );
        $dailyPayments = $this->dailyTotals(
            DB::table('payments')->where('kind', Payment::KIND_CASH),
            'payment_date',
            'COUNT(DISTINCT COALESCE(payment_batch_id, CAST(id AS CHAR)))',
            $periodStart,
            $periodEnd,
        );

        foreach (['credit' => $dailyCredits, 'payment' => $dailyPayments] as $type => $dailyRows) {
            foreach ($dailyRows as $dailyRow) {
                $key = $this->bucketKey(CarbonImmutable::parse($dailyRow->day), $interval);
                $buckets[$key][$type.'_cents'] += Money::sumToCents($dailyRow->total_amount);
                $buckets[$key][$type.'_count'] += (int) $dailyRow->transaction_count;
            }
        }

        return array_values(array_map(fn (array $bucket): array => [
            'label' => $bucket['label'],
            'credit_amount' => Money::fromCents($bucket['credit_cents']),
            'credit_cents' => $bucket['credit_cents'],
            'credit_count' => $bucket['credit_count'],
            'payment_amount' => Money::fromCents($bucket['payment_cents']),
            'payment_cents' => $bucket['payment_cents'],
            'payment_count' => $bucket['payment_count'],
        ], $buckets));
    }

    /**
     * Current unpaid amounts grouped by how far past their deadline they are.
     * Credits without a deadline are counted as not yet overdue.
     *
     * @return list<array{key: string, label: string, remaining_amount: string, remaining_cents: int, entry_count: int}>
     */
    public function agingBuckets(): array
    {
        $today = $this->platformMetrics->today();
        $rows = DB::query()
            ->fromSub($this->platformMetrics->remainingBalancesByEntry(), 'entry_balances')
            ->where('remaining_amount', '>', 0)
            ->selectRaw(
                'CASE
                    WHEN repayment_deadline IS NULL THEN ?
                    WHEN repayment_deadline < ? THEN ?
                    WHEN repayment_deadline < ? THEN ?
                    WHEN repayment_deadline < ? THEN ?
                    ELSE ?
                END AS aging_bucket',
                [
                    'current',
                    $today->subDays(30)->toDateString(), 'overdue_over_30',
                    $today->subDays(7)->toDateString(), 'overdue_8_30',
                    $today->toDateString(), 'overdue_1_7',
                    'current',
                ],
            )
            ->selectRaw('SUM(remaining_amount) AS remaining_amount')
            ->selectRaw('COUNT(*) AS entry_count')
            ->groupBy('aging_bucket')
            ->get()
            ->keyBy('aging_bucket');

        return array_map(function (string $key) use ($rows): array {
            $remainingCents = Money::sumToCents($rows[$key]->remaining_amount ?? 0);

            return [
                'key' => $key,
                'label' => self::AGING_BUCKETS[$key],
                'remaining_amount' => Money::fromCents($remainingCents),
                'remaining_cents' => $remainingCents,
                'entry_count' => (int) ($rows[$key]->entry_count ?? 0),
            ];
        }, array_keys(self::AGING_BUCKETS));
    }

    public function overdueCustomerCount(): int
    {
        return (int) DB::query()
            ->fromSub($this->platformMetrics->remainingBalancesByEntry(), 'entry_balances')
            ->where('remaining_amount', '>', 0)
            ->where('repayment_deadline', '<', $this->platformMetrics->today()->toDateString())
            ->distinct()
            ->count('customer_id');
    }

    /**
     * Stores with period credit and payment totals, current balances,
     * customer count, and the time of their most recent recorded transaction.
     *
     * @return EloquentBuilder<Store>
     */
    public function storeComparison(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $sort, string $direction): EloquentBuilder
    {
        $periodCredits = $this->periodTotalsByStore(DB::table('ledger_entries')->where('ledger_entries.kind', LedgerEntry::KIND_CREDIT), 'ledger_entries', 'transaction_date', $periodStart, $periodEnd);
        $periodPayments = $this->periodTotalsByStore(DB::table('payments')->where('payments.kind', Payment::KIND_CASH), 'payments', 'payment_date', $periodStart, $periodEnd);
        $lastActivity = DB::query()
            ->fromSub(
                $this->recordedAtByStore('ledger_entries')->unionAll($this->recordedAtByStore('payments')),
                'store_activity',
            )
            ->select('store_id')
            ->selectRaw('MAX(recorded_at) AS last_activity_at')
            ->groupBy('store_id');

        return $this->platformMetrics->storesWithBalances()
            ->selectRaw('COALESCE(period_credits.total_amount, 0) AS period_credit_total')
            ->selectRaw('COALESCE(period_payments.total_amount, 0) AS period_payment_total')
            ->selectRaw('last_activity.last_activity_at AS last_activity_at')
            ->leftJoinSub($periodCredits, 'period_credits', 'period_credits.store_id', '=', 'stores.id')
            ->leftJoinSub($periodPayments, 'period_payments', 'period_payments.store_id', '=', 'stores.id')
            ->leftJoinSub($lastActivity, 'last_activity', 'last_activity.store_id', '=', 'stores.id')
            ->orderBy(self::COMPARISON_SORT_COLUMNS[$sort], $direction)
            ->orderBy('stores.id');
    }

    private function dailyTotals(
        Builder $query,
        string $dateColumn,
        string $countExpression,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Collection {
        return $query
            ->where($dateColumn, '>=', $periodStart->toDateString())
            ->where($dateColumn, '<', $periodEnd->addDay()->toDateString())
            ->selectRaw("SUBSTR({$dateColumn}, 1, 10) AS day")
            ->selectRaw('SUM(amount) AS total_amount')
            ->selectRaw("{$countExpression} AS transaction_count")
            ->groupByRaw("SUBSTR({$dateColumn}, 1, 10)")
            ->get();
    }

    private function periodTotalsByStore(
        Builder $query,
        string $table,
        string $dateColumn,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Builder {
        return $query
            ->join('customers', 'customers.id', '=', $table.'.customer_id')
            ->where($table.'.'.$dateColumn, '>=', $periodStart->toDateString())
            ->where($table.'.'.$dateColumn, '<', $periodEnd->addDay()->toDateString())
            ->select('customers.store_id')
            ->selectRaw("SUM({$table}.amount) AS total_amount")
            ->groupBy('customers.store_id');
    }

    private function recordedAtByStore(string $table): Builder
    {
        return DB::table($table)
            ->join('customers', 'customers.id', '=', $table.'.customer_id')
            ->select('customers.store_id')
            ->selectRaw("MAX({$table}.created_at) AS recorded_at")
            ->groupBy('customers.store_id');
    }

    private function bucketStart(CarbonImmutable $date, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    private function bucketKey(CarbonImmutable $date, string $interval): string
    {
        return $this->bucketStart($date, $interval)->toDateString();
    }

    private function bucketLabel(CarbonImmutable $bucketStart, string $interval): string
    {
        return match ($interval) {
            'week' => 'Week of '.$bucketStart->format('M j'),
            'month' => $bucketStart->format('M Y'),
            default => $bucketStart->format('M j'),
        };
    }
}
