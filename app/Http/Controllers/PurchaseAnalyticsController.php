<?php

namespace App\Http\Controllers;

use App\Services\AssociationRuleDiagnosticsService;
use App\Services\PurchasePatternAnalyticsService;
use App\Services\RecommendationEvaluationService;
use App\Services\RecommendationModelStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Purchase pattern analytics and recommendation evaluation for the
 * signed-in owner's store (DUMS-41). The store always comes from the
 * owner's account; any store ID in the request is ignored. Read-only:
 * nothing is trained, activated, or written, and the offline evaluation
 * runs only when the owner asks for it (`evaluate=1`).
 */
class PurchaseAnalyticsController extends Controller
{
    private const DEFAULT_PERIOD_DAYS = 90;

    private const MAX_PERIOD_DAYS = 731;

    public function __invoke(
        Request $request,
        PurchasePatternAnalyticsService $analytics,
        RecommendationModelStatusService $modelStatus,
        AssociationRuleDiagnosticsService $diagnostics,
        RecommendationEvaluationService $evaluation,
    ): View {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'evaluate' => ['nullable', 'boolean'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        $timezone = (string) config('app.timezone');
        $periodEnd = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $timezone) : CarbonImmutable::now($timezone)->startOfDay();
        $periodStart = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $timezone) : $periodEnd->subDays(self::DEFAULT_PERIOD_DAYS - 1);

        if ((int) $periodStart->diffInDays($periodEnd) + 1 > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a period of two years or less.']);
        }

        $store = $request->user()->store;
        $from = $periodStart->toDateString();
        $to = $periodEnd->toDateString();

        return view('analytics.purchases', [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'totals' => $analytics->storeTotals($store),
            'summary' => $analytics->summary($store, $from, $to),
            'model' => $modelStatus->forStore($store),
            'diagnostics' => $diagnostics->forStore($store),
            'evaluation' => $request->boolean('evaluate') ? $evaluation->evaluate($store, $from, $to) : null,
        ]);
    }
}
