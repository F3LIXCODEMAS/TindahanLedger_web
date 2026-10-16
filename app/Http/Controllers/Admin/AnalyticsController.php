<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\PlatformAnalyticsService;
use App\Services\PlatformMetricsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    private const MAX_PERIOD_DAYS = 731;

    private const MAX_DAILY_PERIOD_DAYS = 92;

    public function __invoke(
        Request $request,
        PlatformMetricsService $platformMetrics,
        PlatformAnalyticsService $platformAnalytics,
    ): View {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'interval' => ['nullable', Rule::in(PlatformAnalyticsService::INTERVALS)],
            'sort' => ['nullable', Rule::in(array_keys(PlatformAnalyticsService::COMPARISON_SORT_COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        $timezone = (string) config('app.timezone');
        $today = $platformMetrics->today();
        $periodEnd = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $timezone) : $today;
        $periodStart = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], $timezone)
            : $periodEnd->subDays(29);
        $interval = $validated['interval'] ?? 'day';
        $periodDays = (int) $periodStart->diffInDays($periodEnd) + 1;

        if ($periodDays > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'from' => 'Choose a period of two years or less.',
            ]);
        }

        if ($interval === 'day' && $periodDays > self::MAX_DAILY_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'interval' => 'Daily trends are limited to 92 days. Choose weekly or monthly for longer periods.',
            ]);
        }

        $sort = $validated['sort'] ?? 'outstanding';
        $direction = $validated['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $storeComparison = $platformAnalytics->storeComparison($periodStart, $periodEnd, $sort, $direction)
            ->paginate(20)
            ->withQueryString();

        $storeComparison->getCollection()->each(function (Store $store): void {
            foreach (['outstanding_balance', 'overdue_balance', 'period_credit_total', 'period_payment_total'] as $column) {
                $store->setAttribute($column, Money::fromCents(Money::sumToCents($store->getAttribute($column))));
            }

            $store->setAttribute('customer_count', (int) $store->customer_count);
        });

        return view('admin.analytics.index', [
            'overview' => $platformMetrics->overview($periodStart, $periodEnd),
            'trends' => $platformAnalytics->trends($periodStart, $periodEnd, $interval),
            'agingBuckets' => $platformAnalytics->agingBuckets(),
            'overdueCustomerCount' => $platformAnalytics->overdueCustomerCount(),
            'storeComparison' => $storeComparison,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'interval' => $interval,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }
}
