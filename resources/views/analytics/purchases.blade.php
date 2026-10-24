@php
    $percent = fn (string $ratio): string => number_format((float) $ratio * 100, 1).'%';
    $decimal = fn (string $value, int $places = 2): string => number_format((float) $value, $places);
    $categoryLabels = [
        'metrics_mismatch' => 'Does not match current records',
        'insufficient_observations' => 'Insufficient observations',
        'low_support' => 'Low support',
        'low_confidence' => 'Low confidence',
        'weak_association' => 'Weak or non-positive association',
        'meets_thresholds' => 'Meets configured thresholds',
    ];
    $statusLabels = [
        'active' => 'Active',
        'insufficient_data' => 'Not enough data',
        'failed' => 'Last training failed',
        'building' => 'Training in progress',
        'validated' => 'Validated, not active',
        'superseded' => 'Superseded',
        'not_trained' => 'Not trained yet',
    ];
    $thresholds = $model['configured_thresholds'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Analytics | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('dashboard') }}" aria-label="TindahanLedger dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>

        <nav class="owner-navigation" aria-label="Owner navigation">
            <a class="owner-nav-link" href="{{ route('dashboard') }}">Dashboard</a>
            <a class="owner-nav-link is-current" href="{{ route('purchase-analytics.index') }}" aria-current="page">Purchase analytics</a>
            <a class="owner-nav-link" href="{{ route('products.index') }}">Products</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content analytics-content">
        <header class="dashboard-heading">
            <p class="eyebrow">PURCHASE PATTERNS</p>
            <h1>Purchase Analytics</h1>
            <p>What your recorded sales show, how the suggestion model was built, and how well its suggestions would have matched past sales. Debts, payments, and balances are not part of this page.</p>
        </header>

        <form class="analytics-filters" method="GET" action="{{ route('purchase-analytics.index') }}">
            <div class="analytics-field">
                <label for="analytics-from">From</label>
                <input id="analytics-from" name="from" type="date" value="{{ $periodStart->toDateString() }}" @error('from') aria-invalid="true" @enderror>
            </div>
            <div class="analytics-field">
                <label for="analytics-to">To</label>
                <input id="analytics-to" name="to" type="date" value="{{ $periodEnd->toDateString() }}" @error('to') aria-invalid="true" @enderror>
            </div>
            <button class="overdue-filter-button" type="submit">Apply</button>
        </form>

        @if ($errors->any())
            <div class="field-error analytics-errors" role="alert">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <p class="analytics-period">Period: {{ $periodStart->format('M j, Y') }} – {{ $periodEnd->format('M j, Y') }}, by purchase date.</p>

        {{-- 1. Purchase summary --}}
        <section class="analytics-section" aria-labelledby="summary-title">
            <div class="section-heading"><div><p class="eyebrow">SUMMARY</p><h2 id="summary-title">Purchase summary</h2></div></div>

            @if ($summary['basket_count'] === 0)
                <p class="analytics-empty">
                    @if ($totals['purchases'] === 0)
                        No purchases with products have been recorded yet. Credits and cash sales with catalog products will appear here.
                    @elseif ($totals['eligible_baskets'] === 0)
                        Purchases exist, but none has an eligible product line (for example, all quantities are zero or the products cannot be found).
                    @else
                        No eligible purchases fall in this period. Try a wider date range.
                    @endif
                </p>
            @else
                <div class="analytics-metrics">
                    <article class="metric-panel"><h3>Purchase baskets</h3><p class="metric-value">{{ number_format($summary['basket_count']) }}</p><p class="metric-note">Distinct eligible purchases</p></article>
                    <article class="metric-panel"><h3>Products sold</h3><p class="metric-value">{{ number_format($summary['distinct_products']) }}</p><p class="metric-note">Distinct products in those baskets</p></article>
                    <article class="metric-panel"><h3>Cash sales</h3><p class="metric-value">{{ number_format($summary['baskets_by_type']['cash']) }}</p><p class="metric-note">Baskets paid in cash</p></article>
                    <article class="metric-panel"><h3>Utang purchases</h3><p class="metric-value">{{ number_format($summary['baskets_by_type']['utang']) }}</p><p class="metric-note">Baskets recorded on credit</p></article>
                </div>
                <p class="analytics-note">A basket is one purchase. A basket with three products counts once. Repayments are never counted as purchases.</p>
            @endif
        </section>

        {{-- 2. Frequently recorded products --}}
        @if ($summary['top_products'] !== [])
            <section class="analytics-section" aria-labelledby="products-title">
                <div class="section-heading"><div><p class="eyebrow">PRODUCTS</p><h2 id="products-title">Frequently recorded products</h2></div><span class="activity-limit">Top {{ count($summary['top_products']) }}</span></div>
                <ol class="analytics-list">
                    @foreach ($summary['top_products'] as $product)
                        <li class="analytics-card">
                            <strong class="analytics-name">{{ $product['name'] }}</strong>
                            <dl class="analytics-figures">
                                <div><dt>Baskets</dt><dd>{{ number_format($product['basket_count']) }}</dd></div>
                                <div><dt>Share of baskets</dt><dd>{{ $percent($product['basket_share']) }}</dd></div>
                                <div><dt>Total quantity</dt><dd>{{ $product['total_quantity'] }}</dd></div>
                            </dl>
                        </li>
                    @endforeach
                </ol>
                <p class="analytics-note">Share of baskets = baskets containing the product ÷ all eligible baskets in the period. Total quantity adds up every recorded quantity of the product.</p>
            </section>
        @endif

        {{-- 3. Common product associations --}}
        @if ($summary['basket_count'] > 0)
            <section class="analytics-section" aria-labelledby="pairs-title">
                <div class="section-heading"><div><p class="eyebrow">ASSOCIATIONS</p><h2 id="pairs-title">Products often bought together</h2></div></div>
                @if ($summary['top_pairs'] === [])
                    <p class="analytics-empty">No two products were bought together in at least {{ $summary['min_pair_count'] }} baskets in this period, the minimum the model also uses.</p>
                @else
                    <ol class="analytics-list">
                        @foreach ($summary['top_pairs'] as $pair)
                            <li class="analytics-card">
                                <strong class="analytics-name">{{ $pair['first_name'] }} + {{ $pair['second_name'] }}</strong>
                                <dl class="analytics-figures">
                                    <div><dt>Baskets with both</dt><dd>{{ number_format($pair['pair_count']) }}</dd></div>
                                    <div><dt>Support</dt><dd>{{ $percent($pair['support']) }}</dd></div>
                                </dl>
                                @if ($pair['rules'] === [])
                                    <p class="analytics-note">The active model has no rule for this pair.</p>
                                @else
                                    <ul class="analytics-rules">
                                        @foreach ($pair['rules'] as $rule)
                                            <li>Model rule: {{ $rule['antecedent_product_id'] === $pair['first_product_id'] ? $pair['first_name'] : $pair['second_name'] }} → {{ $rule['consequent_product_id'] === $pair['first_product_id'] ? $pair['first_name'] : $pair['second_name'] }}: confidence {{ $percent($rule['confidence']) }}, lift {{ $decimal($rule['lift']) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <p class="analytics-note">A pair means the products appeared in the same recorded purchases; it does not show that one causes the other. Support is for this period. Confidence and lift come from the active model, which was trained on its own set of baskets.</p>
                @endif
            </section>
        @endif

        {{-- 4. Active model --}}
        <section class="analytics-section" aria-labelledby="model-title">
            <div class="section-heading"><div><p class="eyebrow">MODEL</p><h2 id="model-title">Suggestion model</h2></div></div>
            <dl class="analytics-facts">
                <div><dt>Status</dt><dd>{{ $statusLabels[$model['status']] ?? $model['status'] }}</dd></div>
                <div><dt>Scope</dt><dd>This store only. There is no shared or global model.</dd></div>
                @if ($model['active'])
                    <div><dt>Trained</dt><dd>{{ $model['active']->completed_at?->format('M j, Y g:i A') ?? 'Not recorded' }} ({{ config('app.timezone') }})</dd></div>
                    <div><dt>Activated</dt><dd>{{ $model['active']->activated_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd></div>
                    <div><dt>Baskets used for training</dt><dd>{{ number_format($model['active']->eligible_basket_count) }}</dd></div>
                    <div><dt>Active rules</dt><dd>{{ number_format($model['active_rule_count']) }}</dd></div>
                @endif
            </dl>
            @if (! $model['active'])
                <p class="analytics-empty">
                    No suggestion model is active, so the product picker shows no suggestions.
                    @if ($model['latest_run']?->status === 'insufficient_data')
                        The last training run found {{ number_format($model['latest_run']->eligible_basket_count) }} eligible baskets; at least {{ $thresholds['min_baskets'] }} are needed. Record more sales with products, then train again.
                    @elseif ($model['latest_run']?->status === 'failed')
                        The last training run failed: {{ $model['latest_run']->failure_reason }}
                    @else
                        The model has not been trained for this store yet.
                    @endif
                </p>
            @endif
            @if ($model['thresholds_changed_since_training'])
                <p class="analytics-note" role="status">The configured thresholds have changed since this model was trained. It keeps the rules it was trained with until it is trained again.</p>
            @endif

            <h3 class="analytics-subheading">Configured thresholds</h3>
            <dl class="analytics-facts">
                <div><dt>Minimum support</dt><dd>{{ $percent($thresholds['min_support']) }} <span>How often a product pair appears across eligible baskets.</span></dd></div>
                <div><dt>Minimum confidence</dt><dd>{{ $percent($thresholds['min_confidence']) }} <span>How often the suggested product appears in baskets that contain the first product.</span></dd></div>
                <div><dt>Minimum lift</dt><dd>{{ $thresholds['min_lift'] === null ? 'Off' : $decimal($thresholds['min_lift']) }} <span>Observed co-occurrence compared with what independence would predict. Above 1 is a positive association under this measure, not causation.</span></dd></div>
                <div><dt>Minimum baskets</dt><dd>{{ number_format($thresholds['min_baskets']) }} <span>Eligible baskets a store needs before a model is published.</span></dd></div>
                <div><dt>Minimum co-occurrence</dt><dd>{{ number_format($thresholds['min_pair_count']) }} <span>Baskets that must contain both products.</span></dd></div>
                <div><dt>Minimum first-product baskets</dt><dd>{{ number_format($thresholds['min_antecedent_count']) }} <span>Baskets that must contain the first product of a rule.</span></dd></div>
            </dl>
            <p class="analytics-note">These figures describe patterns in recorded purchases. They do not predict a customer's next purchase.</p>
        </section>

        {{-- 5. Rule quality --}}
        <section class="analytics-section" aria-labelledby="quality-title">
            <div class="section-heading"><div><p class="eyebrow">RULE QUALITY</p><h2 id="quality-title">Rule checks</h2></div></div>
            @if ($diagnostics['status'] !== 'available')
                <p class="analytics-empty">{{ $diagnostics['reason'] }}</p>
            @else
                <p class="analytics-note">Each rule was recalculated from the {{ number_format($diagnostics['recomputed_basket_count']) }} baskets the model was trained on and checked against the configured thresholds. Passing the thresholds does not prove a rule is useful in practice.</p>
                <dl class="analytics-facts analytics-category-counts">
                    @foreach ($diagnostics['category_counts'] as $category => $count)
                        @if ($count > 0)
                            <div><dt>{{ $categoryLabels[$category] }}</dt><dd>{{ number_format($count) }}</dd></div>
                        @endif
                    @endforeach
                </dl>
                @if ($diagnostics['few_observations_count'] > 0)
                    <p class="analytics-note">{{ $diagnostics['few_observations_count'] }} of {{ $diagnostics['rule_count'] }} rules rest on fewer than {{ \App\Services\AssociationRuleDiagnosticsService::FEW_OBSERVATIONS_PAIR_COUNT }} baskets. This is a reading aid for this page, not one of the model's thresholds.</p>
                @endif
                <ol class="analytics-list">
                    @foreach ($diagnostics['rules'] as $rule)
                        <li class="analytics-card">
                            <strong class="analytics-name">{{ $rule['antecedent'] }} → {{ $rule['consequent'] }}</strong>
                            <span class="analytics-badge is-{{ str_replace('_', '-', $rule['category']) }}">{{ $categoryLabels[$rule['category']] }}{{ $rule['few_observations'] ? ' · few observations' : '' }}</span>
                            <dl class="analytics-figures">
                                <div><dt>Both</dt><dd>{{ number_format($rule['pair_count']) }}</dd></div>
                                <div><dt>Support</dt><dd>{{ $percent($rule['support']) }}</dd></div>
                                <div><dt>Confidence</dt><dd>{{ $percent($rule['confidence']) }}</dd></div>
                                <div><dt>Lift</dt><dd>{{ $decimal($rule['lift']) }}</dd></div>
                            </dl>
                        </li>
                    @endforeach
                </ol>
                @if ($diagnostics['rule_count'] > count($diagnostics['rules']))
                    <p class="analytics-note">Showing {{ count($diagnostics['rules']) }} of {{ number_format($diagnostics['rule_count']) }} rules, those needing attention first.</p>
                @endif
            @endif
        </section>

        {{-- 6. Offline evaluation --}}
        <section class="analytics-section" aria-labelledby="evaluation-title">
            <div class="section-heading"><div><p class="eyebrow">EVALUATION</p><h2 id="evaluation-title">How well would suggestions have matched past sales?</h2></div></div>
            @if ($evaluation === null)
                <p class="analytics-note">Runs an offline test on the selected period without changing the live model.</p>
                <form method="GET" action="{{ route('purchase-analytics.index') }}">
                    <input type="hidden" name="from" value="{{ $periodStart->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $periodEnd->toDateString() }}">
                    <input type="hidden" name="evaluate" value="1">
                    <button class="overdue-filter-button" type="submit">Run offline evaluation</button>
                </form>
            @elseif ($evaluation['status'] !== 'available')
                <p class="analytics-empty">Evaluation is not available for this period.</p>
                <ul class="analytics-reasons">
                    @foreach ($evaluation['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @else
                <p class="analytics-badge {{ $evaluation['label'] === 'exploratory' ? 'is-low-confidence' : 'is-meets-thresholds' }}">{{ $evaluation['label'] === 'exploratory' ? 'Exploratory: fewer than '.\App\Services\RecommendationEvaluationService::EXPLORATORY_BELOW.' baskets evaluated' : 'Offline result on recorded sales' }}</p>
                <dl class="analytics-facts">
                    <div><dt>Training baskets</dt><dd>{{ number_format($evaluation['split']['training_baskets']) }} ({{ $evaluation['split']['training_period'][0] }} to {{ $evaluation['split']['training_period'][1] }})</dd></div>
                    <div><dt>Later baskets</dt><dd>{{ number_format($evaluation['split']['held_out_baskets']) }} ({{ $evaluation['split']['held_out_period'][0] }} to {{ $evaluation['split']['held_out_period'][1] }})</dd></div>
                    <div><dt>Baskets evaluated</dt><dd>{{ number_format($evaluation['split']['evaluated_baskets']) }} <span>Later baskets with two or more products; {{ number_format($evaluation['split']['single_item_held_out_baskets']) }} single-product baskets were skipped.</span></dd></div>
                    <div><dt>Rules from training baskets</dt><dd>{{ number_format($evaluation['training_rule_count']) }}</dd></div>
                </dl>
                <table class="analytics-table">
                    <caption>Top {{ $evaluation['method']['k'] }} suggestions per basket</caption>
                    <thead><tr><th scope="col">Measure</th><th scope="col">Association rules</th><th scope="col">Most popular products</th></tr></thead>
                    <tbody>
                        @foreach (['coverage' => 'Coverage', 'hit_rate' => 'Hit rate@'.$evaluation['method']['k'], 'precision' => 'Precision@'.$evaluation['method']['k'], 'recall' => 'Recall@'.$evaluation['method']['k']] as $metric => $label)
                            <tr><th scope="row">{{ $label }}</th><td>{{ $percent($evaluation['methods']['apriori'][$metric]) }}</td><td>{{ $percent($evaluation['methods']['popularity'][$metric]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- 7. Method and limitations --}}
        <section class="analytics-section" aria-labelledby="method-title">
            <div class="section-heading"><div><p class="eyebrow">METHOD</p><h2 id="method-title">How the evaluation works</h2></div></div>
            <ul class="analytics-reasons">
                <li>Eligible baskets in the period are put in date order. About the earliest {{ \App\Services\RecommendationEvaluationService::DEFAULT_TRAIN_PERCENT }}% train the rules (ending at the end of a day); the later baskets are kept apart and never used for training.</li>
                <li>In each later basket with two or more products, the last product entered is hidden. The other products are the input, as when you are part-way through recording a sale.</li>
                <li>Association rules suggest up to {{ \App\Services\RecommendationEvaluationService::K }} products the same way the product picker does. The comparison suggests the {{ \App\Services\RecommendationEvaluationService::K }} products found in the most training baskets. Neither suggests products already in the basket or now inactive.</li>
                <li>Coverage: baskets that got any suggestion. Hit rate: baskets whose hidden product was suggested; a basket with no suggestion is a miss. Precision: correct suggestions ÷ ({{ \App\Services\RecommendationEvaluationService::K }} × baskets). Recall: hidden products found ÷ baskets, equal to the hit rate because one product is hidden per basket.</li>
                <li>This is an offline test on recorded sales. A higher score does not show more sales, profit, or customer satisfaction. Customer identities are never used.</li>
            </ul>
        </section>
    </main>
</body>
</html>
