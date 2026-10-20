<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, PlatformMetricsService $platformMetrics): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);
        $timezone = (string) config('app.timezone');
        $today = $platformMetrics->today();
        $periodStart = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'], $timezone)
            : $today->startOfMonth();
        $periodEnd = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'], $timezone)
            : $today;

        return view('admin.dashboard', [
            'metrics' => $platformMetrics->overview($periodStart, $periodEnd),
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ]);
    }
}
