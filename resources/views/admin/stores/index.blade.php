@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', 'Stores')

@section('content')
    <div class="admin-panel-heading">
        <header class="dashboard-heading">
            <p class="eyebrow">STORE MANAGEMENT</p>
            <h1>Stores</h1>
            <p>Registered stores with their owners and current balances.</p>
        </header>
        <a class="admin-button" href="{{ route('admin.stores.create') }}">Create store</a>
    </div>

    <form class="admin-filters" method="GET" action="{{ route('admin.stores.index') }}">
        <div class="admin-field">
            <label for="store-search">Search</label>
            <input id="store-search" name="search" type="search" value="{{ $search }}" maxlength="120" placeholder="Store or owner name">
        </div>
        <div class="admin-field">
            <label for="store-status">Status</label>
            <select id="store-status" name="status">
                <option value="" @selected($status === '')>All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="suspended" @selected($status === 'suspended')>Suspended</option>
            </select>
        </div>
        <div class="admin-field">
            <label for="store-sort">Sort by</label>
            <select id="store-sort" name="sort">
                <option value="registered" @selected($sort === 'registered')>Registration date</option>
                <option value="name" @selected($sort === 'name')>Store name</option>
                <option value="customers" @selected($sort === 'customers')>Customer count</option>
                <option value="outstanding" @selected($sort === 'outstanding')>Outstanding debt</option>
            </select>
        </div>
        <div class="admin-field">
            <label for="store-direction">Order</label>
            <select id="store-direction" name="direction">
                <option value="desc" @selected($direction === 'desc')>Descending</option>
                <option value="asc" @selected($direction === 'asc')>Ascending</option>
            </select>
        </div>
        <button class="admin-button" type="submit">Apply</button>
    </form>

    <section class="admin-panel" aria-labelledby="store-results-title">
        <div class="admin-panel-heading">
            <h2 id="store-results-title">Registered stores</h2>
            <span class="admin-muted">{{ $stores->total() }} {{ $stores->total() === 1 ? 'store' : 'stores' }}</span>
        </div>

        @if ($stores->isEmpty())
            <div class="activity-empty" role="status">
                <span class="empty-mark" aria-hidden="true">—</span>
                <p>{{ $search === '' && $status === '' ? 'No stores are registered yet.' : 'No stores match these filters.' }}</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Store</th>
                        <th scope="col">Owner</th>
                        <th scope="col">Status</th>
                        <th scope="col">Registered</th>
                        <th scope="col" class="is-amount">Customers</th>
                        <th scope="col" class="is-amount">Outstanding</th>
                        <th scope="col" class="is-amount">Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stores as $store)
                        <tr>
                            <td data-label="ID">{{ $store->id }}</td>
                            <td data-label="Store"><a href="{{ route('admin.stores.show', $store) }}">{{ $store->store_name }}</a></td>
                            <td data-label="Owner">
                                {{ $store->owner->name }}
                                <br><span class="admin-muted">{{ $store->owner->email }}</span>
                            </td>
                            <td data-label="Status">
                                <span class="admin-status {{ $store->isActive() ? '' : 'is-warning' }}">{{ ucfirst($store->status) }}</span>
                            </td>
                            <td data-label="Registered">{{ $store->created_at->format('M j, Y') }}</td>
                            <td data-label="Customers" class="is-amount">{{ number_format($store->customer_count) }}</td>
                            <td data-label="Outstanding" class="is-amount">₱{{ $store->outstanding_balance }}</td>
                            <td data-label="Overdue" class="is-amount">₱{{ $store->overdue_balance }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="admin-pagination">{{ $stores->links() }}</div>
        @endif
    </section>
@endsection
