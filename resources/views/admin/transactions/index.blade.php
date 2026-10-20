@extends('admin.layout', ['activeNav' => 'transactions'])

@section('title', 'Transactions')

@section('content')
    <header class="dashboard-heading">
        <p class="eyebrow">TRANSACTION MONITORING</p>
        <h1>Transactions</h1>
        <p>Read-only view of credits and payments recorded by every store.</p>
    </header>

    <form class="admin-filters" method="GET" action="{{ route('admin.transactions.index') }}">
        <div class="admin-field">
            <label for="transaction-from">From</label>
            <input id="transaction-from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="admin-field">
            <label for="transaction-to">To</label>
            <input id="transaction-to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="admin-field">
            <label for="transaction-store">Store</label>
            <select id="transaction-store" name="store_id">
                <option value="">All stores</option>
                @foreach ($stores as $storeOption)
                    <option value="{{ $storeOption->id }}" @selected((int) $filters['store_id'] === $storeOption->id)>{{ $storeOption->store_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="admin-field">
            <label for="transaction-type">Type</label>
            <select id="transaction-type" name="type">
                <option value="" @selected($filters['type'] === null)>Credits and payments</option>
                <option value="credit" @selected($filters['type'] === 'credit')>Credits only</option>
                <option value="payment" @selected($filters['type'] === 'payment')>Payments only</option>
                <option value="loyalty_redemption" @selected($filters['type'] === 'loyalty_redemption')>Suki Points redemptions only</option>
                <option value="late_penalty" @selected($filters['type'] === 'late_penalty')>Late payment fees only</option>
                <option value="penalty_waiver" @selected($filters['type'] === 'penalty_waiver')>Waived fees only</option>
            </select>
        </div>
        <div class="admin-field">
            <label for="transaction-customer">Customer</label>
            <input id="transaction-customer" name="customer" type="search" value="{{ $filters['customer'] }}" maxlength="120" placeholder="Customer name">
        </div>
        <div class="admin-field">
            <label for="transaction-sort">Sort by</label>
            <select id="transaction-sort" name="sort">
                <option value="date" @selected($sort === 'date')>Date and time</option>
                <option value="store" @selected($sort === 'store')>Store name</option>
                <option value="type" @selected($sort === 'type')>Transaction type</option>
                <option value="amount" @selected($sort === 'amount')>Amount</option>
            </select>
        </div>
        <div class="admin-field">
            <label for="transaction-direction">Order</label>
            <select id="transaction-direction" name="direction">
                <option value="desc" @selected($direction === 'desc')>Descending</option>
                <option value="asc" @selected($direction === 'asc')>Ascending</option>
            </select>
        </div>
        <button class="admin-button" type="submit">Apply</button>
    </form>

    <section class="admin-panel" aria-label="Totals for the current filters">
        <dl class="admin-detail-grid">
            <div><dt>Matching transactions</dt><dd>{{ number_format($totals['transaction_count']) }}</dd></div>
            <div><dt>Credits recorded</dt><dd>₱{{ $totals['credit_total'] }}</dd></div>
            <div><dt>Payments received</dt><dd>₱{{ $totals['payment_total'] }}</dd></div>
        </dl>
        <p class="admin-muted">Totals cover every transaction matching the filters, not only this page. Credit totals are amounts lent, not store revenue.</p>
    </section>

    <section class="admin-panel" aria-labelledby="transaction-results-title">
        <h2 id="transaction-results-title">Transaction records</h2>

        @if ($transactions->isEmpty())
            <div class="activity-empty" role="status">
                <span class="empty-mark" aria-hidden="true">—</span>
                <p>No transactions match these filters.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Store</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="is-amount">Amount</th>
                        <th scope="col">Deadline</th>
                        <th scope="col">Status</th>
                        <th scope="col">Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $transaction)
                        <tr>
                            <td data-label="Date">
                                {{ \Illuminate\Support\Carbon::parse($transaction->transaction_date)->format('M j, Y') }}
                                <br><span class="admin-muted">Recorded {{ \Illuminate\Support\Carbon::parse($transaction->recorded_at)->format('M j, Y g:i A') }}</span>
                            </td>
                            <td data-label="Store"><a href="{{ route('admin.stores.show', $transaction->store_id) }}">{{ $transaction->store_name }}</a></td>
                            <td data-label="Customer"><a href="{{ route('admin.stores.customers.show', [$transaction->store_id, $transaction->customer_id]) }}">{{ $transaction->customer_name }}</a></td>
                            <td data-label="Type">{{ match ($transaction->transaction_type) { 'credit' => 'Credit', 'loyalty_redemption' => 'Suki Points redemption', 'late_penalty' => 'Late payment fee', 'penalty_waiver' => 'Late fee waived', default => 'Payment' } }}</td>
                            <td data-label="Amount" class="is-amount">₱{{ $transaction->amount }}</td>
                            <td data-label="Deadline">{{ $transaction->repayment_deadline ? \Illuminate\Support\Carbon::parse($transaction->repayment_deadline)->format('M j, Y') : '—' }}</td>
                            <td data-label="Status">
                                @if ($transaction->status)
                                    <span class="admin-status {{ $transaction->status === 'Overdue' ? 'is-warning' : '' }}">{{ $transaction->status }}</span>
                                    @if ($transaction->status !== 'Paid')
                                        <br><span class="admin-muted">₱{{ $transaction->remaining_amount }} remaining</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="Note">{{ $transaction->note ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="admin-pagination">{{ $transactions->links() }}</div>
        @endif
    </section>
@endsection
