<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('dashboard') }}" aria-label="TindahanLedger dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>

        <nav class="owner-navigation" aria-label="Owner navigation">
            <a class="owner-nav-link is-current" href="{{ route('dashboard') }}" aria-current="page">Dashboard</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content">
        <header class="dashboard-heading">
            <p class="eyebrow">STORE OVERVIEW</p>
            <h1>Dashboard</h1>
            <p>Current balances and recent ledger activity.</p>
        </header>

        <section class="dashboard-metrics" aria-label="Store summary">
            <article class="metric-panel metric-collectibles" aria-labelledby="collectibles-title">
                <div class="metric-label-row">
                    <h2 id="collectibles-title">Total collectibles</h2>
                    <span class="metric-symbol" aria-hidden="true">₱</span>
                </div>
                <p class="metric-value">₱{{ $totalCollectibles }}</p>
                <p class="metric-note">Outstanding customer balances</p>
            </article>

            <article class="metric-panel metric-overdue" aria-labelledby="overdue-title">
                <div class="metric-label-row">
                    <h2 id="overdue-title">Overdue accounts</h2>
                    <span class="overdue-mark" aria-hidden="true"></span>
                </div>
                <p class="metric-value">{{ $overdueAccountCount }}</p>
                <p class="metric-note">Customers with past-due balances</p>
            </article>
        </section>

        <section class="dashboard-customer-actions" aria-labelledby="customer-actions-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">CUSTOMERS</p>
                    <h2 id="customer-actions-title">Customer records</h2>
                </div>
            </div>
            <div class="dashboard-action-links">
                <a class="dashboard-action-link" href="{{ route('customers.create') }}">
                    <span class="action-mark" aria-hidden="true">+</span>
                    <span><strong>Add customer</strong><small>Create a customer ledger profile</small></span>
                </a>
                <a class="dashboard-action-link" href="{{ route('customers.index') }}">
                    <span class="action-mark action-mark-neutral" aria-hidden="true">≡</span>
                    <span><strong>Customer list</strong><small>Review and update customer details</small></span>
                </a>
            </div>
        </section>

        <section class="activity-section" aria-labelledby="activity-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">LEDGER</p>
                    <h2 id="activity-title">Recent activity</h2>
                </div>
                <span class="activity-limit">Latest 8 records</span>
            </div>

            @if ($recentActivity->isEmpty())
                <div class="activity-empty" role="status">
                    <span class="empty-mark" aria-hidden="true">—</span>
                    <p>No recent activity yet.</p>
                </div>
            @else
                <ol class="activity-list">
                    @foreach ($recentActivity as $activity)
                        @php($isPayment = $activity['type'] === 'Payment received')
                        <li class="activity-row">
                            <span class="activity-indicator {{ $isPayment ? 'is-payment' : 'is-credit' }}" aria-hidden="true">
                                {{ $isPayment ? '−' : '+' }}
                            </span>
                            <div class="activity-description">
                                <strong>{{ $activity['customer_name'] }}</strong>
                                <span>{{ $activity['type'] }}</span>
                            </div>
                            <time class="activity-date" datetime="{{ $activity['created_at']->toIso8601String() }}">
                                {{ $activity['created_at']->format('M j, g:i A') }}
                            </time>
                            <span class="activity-amount {{ $isPayment ? 'is-payment' : 'is-credit' }}">
                                {{ $isPayment ? '−' : '+' }}₱{{ $activity['amount'] }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </main>
</body>
</html>
