@extends('admin.layout', ['activeNav' => 'analytics'])

@section('title', 'Analytics')

@section('content')
    <header class="dashboard-heading">
        <p class="eyebrow">PLATFORM ANALYTICS</p>
        <h1>Analytics</h1>
        <p>Credit and repayment activity across stores. Credits are amounts lent to customers, not store revenue or profit.</p>
    </header>

    <form class="admin-filters" method="GET" action="{{ route('admin.analytics') }}">
        <div class="admin-field">
            <label for="analytics-from">From</label>
            <input id="analytics-from" name="from" type="date" value="{{ $periodStart->toDateString() }}">
        </div>
        <div class="admin-field">
            <label for="analytics-to">To</label>
            <input id="analytics-to" name="to" type="date" value="{{ $periodEnd->toDateString() }}">
        </div>
        <div class="admin-field">
            <label for="analytics-interval">Group trends by</label>
            <select id="analytics-interval" name="interval">
                <option value="day" @selected($interval === 'day')>Day</option>
                <option value="week" @selected($interval === 'week')>Week</option>
                <option value="month" @selected($interval === 'month')>Month</option>
            </select>
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <button class="admin-button" type="submit">Apply</button>
    </form>

    <section class="admin-panel" aria-labelledby="overview-title">
        <h2 id="overview-title">Platform overview</h2>
        <dl class="admin-detail-grid">
            <div><dt>Registered stores</dt><dd>{{ number_format($overview['total_stores']) }}</dd></div>
            <div><dt>Active stores</dt><dd>{{ number_format($overview['active_stores']) }}</dd></div>
            <div><dt>Suspended stores</dt><dd>{{ number_format($overview['suspended_stores']) }}</dd></div>
            <div><dt>Registered customers</dt><dd>{{ number_format($overview['total_customers']) }}</dd></div>
            <div><dt>Current outstanding debt</dt><dd>₱{{ $overview['outstanding_balance'] }}</dd></div>
            <div><dt>Current overdue balance</dt><dd>₱{{ $overview['overdue_balance'] }}</dd></div>
            <div><dt>Stores with overdue accounts</dt><dd>{{ number_format($overview['stores_with_overdue']) }}</dd></div>
            <div><dt>Payments received in period</dt><dd>₱{{ $overview['payments_received'] }}</dd></div>
        </dl>
        <p class="admin-muted">Balances are current as of today. Period: {{ $periodStart->format('M j, Y') }} – {{ $periodEnd->format('M j, Y') }}.</p>
    </section>

    <section class="admin-panel" aria-labelledby="trends-title">
        <h2 id="trends-title">Transaction trends</h2>
        @if (collect($trends)->every(fn (array $bucket): bool => $bucket['credit_count'] === 0 && $bucket['payment_count'] === 0))
            <div class="activity-empty" role="status">
                <span class="empty-mark" aria-hidden="true">—</span>
                <p>No credits or payments were recorded in this period.</p>
            </div>
        @else
            @include('admin.analytics.trend-chart')
        @endif
    </section>

    <section class="admin-panel" aria-labelledby="aging-title">
        <div class="admin-panel-heading">
            <h2 id="aging-title">Debt aging</h2>
            <span class="admin-muted">{{ number_format($overdueCustomerCount) }} {{ $overdueCustomerCount === 1 ? 'customer has' : 'customers have' }} overdue debt</span>
        </div>
        <p class="admin-muted">Remaining unpaid amounts as of today, grouped by days past the repayment deadline. Credits without a deadline count as not yet overdue.</p>
        @php($largestAgingCents = max(1, ...array_column($agingBuckets, 'remaining_cents')))
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col">Aging group</th>
                    <th scope="col" class="is-amount">Unpaid credits</th>
                    <th scope="col" class="is-amount">Remaining amount</th>
                    <th scope="col">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($agingBuckets as $bucket)
                    <tr>
                        <td data-label="Aging group">{{ $bucket['label'] }}</td>
                        <td data-label="Unpaid credits" class="is-amount">{{ number_format($bucket['entry_count']) }}</td>
                        <td data-label="Remaining amount" class="is-amount">₱{{ $bucket['remaining_amount'] }}</td>
                        <td data-label="Share">
                            <span class="analytics-meter" aria-hidden="true"><span style="width: {{ round($bucket['remaining_cents'] / $largestAgingCents * 100, 1) }}%"></span></span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="admin-panel" aria-labelledby="comparison-title">
        <div class="admin-panel-heading">
            <h2 id="comparison-title">Store comparison</h2>
            <form class="admin-inline-form" method="GET" action="{{ route('admin.analytics') }}">
                <input type="hidden" name="from" value="{{ $periodStart->toDateString() }}">
                <input type="hidden" name="to" value="{{ $periodEnd->toDateString() }}">
                <input type="hidden" name="interval" value="{{ $interval }}">
                <div class="admin-field">
                    <label for="comparison-sort">Sort by</label>
                    <select id="comparison-sort" name="sort">
                        @foreach ([
                            'outstanding' => 'Current outstanding debt',
                            'overdue' => 'Current overdue balance',
                            'credits' => 'Credits in period',
                            'payments' => 'Payments in period',
                            'customers' => 'Customer count',
                            'activity' => 'Recent activity',
                            'name' => 'Store name',
                        ] as $sortKey => $sortLabel)
                            <option value="{{ $sortKey }}" @selected($sort === $sortKey)>{{ $sortLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="admin-field">
                    <label for="comparison-direction">Order</label>
                    <select id="comparison-direction" name="direction">
                        <option value="desc" @selected($direction === 'desc')>Descending</option>
                        <option value="asc" @selected($direction === 'asc')>Ascending</option>
                    </select>
                </div>
                <button class="admin-button-secondary" type="submit">Sort</button>
            </form>
        </div>

        @if ($storeComparison->isEmpty())
            <p class="admin-muted">No stores are registered yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Store</th>
                        <th scope="col" class="is-amount">Credits in period</th>
                        <th scope="col" class="is-amount">Payments in period</th>
                        <th scope="col" class="is-amount">Outstanding now</th>
                        <th scope="col" class="is-amount">Overdue now</th>
                        <th scope="col" class="is-amount">Customers</th>
                        <th scope="col">Recent activity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($storeComparison as $store)
                        <tr>
                            <td data-label="Store"><a href="{{ route('admin.stores.show', $store) }}">{{ $store->store_name }}</a></td>
                            <td data-label="Credits in period" class="is-amount">₱{{ $store->period_credit_total }}</td>
                            <td data-label="Payments in period" class="is-amount">₱{{ $store->period_payment_total }}</td>
                            <td data-label="Outstanding now" class="is-amount">₱{{ $store->outstanding_balance }}</td>
                            <td data-label="Overdue now" class="is-amount">₱{{ $store->overdue_balance }}</td>
                            <td data-label="Customers" class="is-amount">{{ number_format($store->customer_count) }}</td>
                            <td data-label="Recent activity">{{ $store->last_activity_at ? \Illuminate\Support\Carbon::parse($store->last_activity_at)->format('M j, Y g:i A') : 'No transactions yet' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="admin-pagination">{{ $storeComparison->links() }}</div>
        @endif
    </section>
@endsection
