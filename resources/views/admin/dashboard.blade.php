@extends('admin.layout', ['activeNav' => 'dashboard'])

@section('title', 'Dashboard')

@section('content')
    <header class="dashboard-heading">
        <p class="eyebrow">PLATFORM ADMINISTRATION</p>
        <h1>Super Admin Dashboard</h1>
        <p>Current balances across all stores, plus payments received in the selected period.</p>
    </header>

    <form class="admin-filters" method="GET" action="{{ route('admin.dashboard') }}">
        <div class="admin-field">
            <label for="period-from">Payments from</label>
            <input id="period-from" name="from" type="date" value="{{ $periodStart->toDateString() }}">
        </div>
        <div class="admin-field">
            <label for="period-to">Payments to</label>
            <input id="period-to" name="to" type="date" value="{{ $periodEnd->toDateString() }}">
        </div>
        <button class="admin-button" type="submit">Apply period</button>
    </form>

    @if ($metrics['total_stores'] === 0)
        <div class="activity-empty" role="status">
            <span class="empty-mark" aria-hidden="true">—</span>
            <p>No stores are registered yet. <a href="{{ route('admin.stores.create') }}">Create the first store</a>.</p>
        </div>
    @endif

    <h2 class="admin-muted">Stores and customers</h2>
    <section class="dashboard-metrics admin-metrics" aria-label="Store and customer totals">
        @foreach ([
            ['Total registered stores', number_format($metrics['total_stores'])],
            ['Active stores', number_format($metrics['active_stores'])],
            ['Suspended stores', number_format($metrics['suspended_stores'])],
            ['Total registered customers', number_format($metrics['total_customers'])],
        ] as [$label, $value])
            <article class="metric-panel">
                <h3 class="metric-note">{{ $label }}</h3>
                <p class="metric-value">{{ $value }}</p>
            </article>
        @endforeach
    </section>

    <h2 class="admin-muted">Current balances (as of today)</h2>
    <section class="dashboard-metrics admin-metrics" aria-label="Current balances">
        <article class="metric-panel">
            <h3 class="metric-note">Total outstanding debt (principal + late fees)</h3>
            <p class="metric-value">₱{{ $metrics['outstanding_balance'] }}</p>
            <p class="metric-note">Remaining unpaid credit across all stores</p>
        </article>
        <article class="metric-panel">
            <h3 class="metric-note">Total overdue balance</h3>
            <p class="metric-value">₱{{ $metrics['overdue_balance'] }}</p>
            <p class="metric-note">Unpaid credit past its repayment deadline</p>
        </article>
        <article class="metric-panel">
            <h3 class="metric-note">Stores with overdue accounts</h3>
            <p class="metric-value">{{ number_format($metrics['stores_with_overdue']) }}</p>
        </article>
    </section>

    <h2 class="admin-muted">Selected period: {{ $periodStart->format('M j, Y') }} – {{ $periodEnd->format('M j, Y') }}</h2>
    <section class="dashboard-metrics admin-metrics" aria-label="Period totals">
        <article class="metric-panel">
            <h3 class="metric-note">Total payments received</h3>
            <p class="metric-value">₱{{ $metrics['payments_received'] }}</p>
            <p class="metric-note">Sum of payment records dated in this period</p>
        </article>
    </section>
@endsection
