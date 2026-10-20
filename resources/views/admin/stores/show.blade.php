@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', $store->store_name)

@section('content')
    <a class="back-link" href="{{ route('admin.stores.index') }}">← Back to stores</a>

    <div class="admin-panel-heading">
        <header class="dashboard-heading">
            <p class="eyebrow">STORE #{{ $store->id }}</p>
            <h1>{{ $store->store_name }}</h1>
            <p>
                <span class="admin-status {{ $store->isActive() ? '' : 'is-warning' }}">{{ ucfirst($store->status) }}</span>
                Registered {{ $store->created_at->format('M j, Y') }}
            </p>
        </header>
        <a class="admin-button-secondary" href="{{ route('admin.stores.edit', $store) }}">Edit store details</a>
    </div>

    <section class="admin-panel" aria-labelledby="store-summary-title">
        <h2 id="store-summary-title">Summary</h2>
        <dl class="admin-detail-grid">
            <div><dt>Owner</dt><dd><a href="{{ route('admin.users.show', $store->owner) }}">{{ $store->owner->name }}</a></dd></div>
            <div><dt>Owner username</dt><dd>{{ $store->owner->username }}</dd></div>
            <div><dt>Owner email</dt><dd>{{ $store->owner->email }}</dd></div>
            <div><dt>Owner account</dt><dd>{{ $store->owner->isActive() ? 'Active' : 'Deactivated' }}</dd></div>
            <div><dt>Customers</dt><dd>{{ number_format($store->customer_count) }}</dd></div>
            <div><dt>Current outstanding debt (principal + late fees)</dt><dd>₱{{ $store->outstanding_balance }}</dd></div>
            <div><dt>Current overdue balance</dt><dd>₱{{ $store->overdue_balance }}</dd></div>
        </dl>
    </section>

    <section class="admin-panel" aria-labelledby="store-access-title">
        <h2 id="store-access-title">Store access</h2>
        @if ($store->isActive())
            <p class="admin-muted">Suspending the store keeps every record but stops the owner from adding customers or recording credits and payments.</p>
            <form class="admin-inline-form" method="POST" action="{{ route('admin.stores.status.update', $store) }}" data-confirm="Suspend {{ $store->store_name }}? The owner will not be able to record credits or payments until it is reactivated.">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="suspended">
                <button class="admin-button-danger" type="submit">Suspend store</button>
            </form>
        @else
            <p class="admin-muted">This store is suspended. Its records are read-only for the owner.</p>
            <form class="admin-inline-form" method="POST" action="{{ route('admin.stores.status.update', $store) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="active">
                <button class="admin-button" type="submit">Reactivate store</button>
            </form>
        @endif
    </section>

    <section class="admin-panel" aria-labelledby="store-owner-title">
        <h2 id="store-owner-title">Reassign owner</h2>
        @if ($availableOwners->isEmpty())
            <p class="admin-muted">No unassigned active owner accounts are available. <a href="{{ route('admin.users.create') }}">Create an owner account</a> first.</p>
        @else
            <form class="admin-inline-form" method="POST" action="{{ route('admin.stores.owner.update', $store) }}" data-confirm="Reassign {{ $store->store_name }} to the selected owner? The current owner will lose access to this store.">
                @csrf
                @method('PATCH')
                <div class="admin-field">
                    <label for="owner_user_id">New owner</label>
                    <select id="owner_user_id" name="owner_user_id" required>
                        @foreach ($availableOwners as $availableOwner)
                            <option value="{{ $availableOwner->id }}">{{ $availableOwner->name }} ({{ $availableOwner->username }})</option>
                        @endforeach
                    </select>
                </div>
                <button class="admin-button-secondary" type="submit">Reassign owner</button>
            </form>
        @endif
    </section>

    <section class="admin-panel" aria-labelledby="store-customers-title">
        <div class="admin-panel-heading">
            <h2 id="store-customers-title">Customers</h2>
            <span class="admin-muted">{{ $customers->total() }} {{ $customers->total() === 1 ? 'customer' : 'customers' }}</span>
        </div>

        @if ($customers->isEmpty())
            <p class="admin-muted">This store has no customers yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Contact</th>
                        <th scope="col" class="is-amount">Credit limit</th>
                        <th scope="col" class="is-amount">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        <tr>
                            <td data-label="Customer"><a href="{{ route('admin.stores.customers.show', [$store, $customer]) }}">{{ $customer->full_name }}</a></td>
                            <td data-label="Contact">{{ $customer->contact_number }}</td>
                            <td data-label="Credit limit" class="is-amount">₱{{ $customer->credit_limit }}</td>
                            <td data-label="Outstanding" class="is-amount">₱{{ $customer->outstanding_balance }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="admin-pagination">{{ $customers->links() }}</div>
        @endif
    </section>

    <section class="admin-panel" aria-labelledby="store-activity-title">
        <div class="admin-panel-heading">
            <h2 id="store-activity-title">Recent transactions</h2>
            <a class="admin-button-secondary" href="{{ route('admin.transactions.index', ['store_id' => $store->id]) }}">View all transactions</a>
        </div>
        @if ($recentActivity->isEmpty())
            <p class="admin-muted">No credits or payments have been recorded for this store.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Recorded</th>
                        <th scope="col">Type</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="is-amount">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentActivity as $activity)
                        <tr>
                            <td data-label="Recorded">{{ $activity['occurred_at']->format('M j, Y g:i A') }}</td>
                            <td data-label="Type">{{ $activity['type'] }}</td>
                            <td data-label="Customer">{{ $activity['customer_name'] }}</td>
                            <td data-label="Amount" class="is-amount">₱{{ $activity['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @include('admin.partials.audit-log', ['auditLogs' => $auditLogs])
@endsection
