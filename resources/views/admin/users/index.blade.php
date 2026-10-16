@extends('admin.layout', ['activeNav' => 'users'])

@section('title', 'Users')

@section('content')
    <div class="admin-panel-heading">
        <header class="dashboard-heading">
            <p class="eyebrow">USER MANAGEMENT</p>
            <h1>Users</h1>
            <p>Store owner and administrator accounts.</p>
        </header>
        <a class="admin-button" href="{{ route('admin.users.create') }}">Create owner account</a>
    </div>

    <form class="admin-filters" method="GET" action="{{ route('admin.users.index') }}">
        <div class="admin-field">
            <label for="user-search">Search</label>
            <input id="user-search" name="search" type="search" value="{{ $search }}" maxlength="120" placeholder="Name, username, or email">
        </div>
        <div class="admin-field">
            <label for="user-store">Store</label>
            <select id="user-store" name="store_id">
                <option value="">All stores</option>
                @foreach ($stores as $storeOption)
                    <option value="{{ $storeOption->id }}" @selected($storeId === $storeOption->id)>{{ $storeOption->store_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="admin-field">
            <label for="user-role">Role</label>
            <select id="user-role" name="role">
                <option value="" @selected($role === '')>All roles</option>
                <option value="store_owner" @selected($role === 'store_owner')>Store owner</option>
                <option value="super_admin" @selected($role === 'super_admin')>Super Admin</option>
            </select>
        </div>
        <div class="admin-field">
            <label for="user-status">Status</label>
            <select id="user-status" name="status">
                <option value="" @selected($status === '')>All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Deactivated</option>
            </select>
        </div>
        <button class="admin-button" type="submit">Apply</button>
    </form>

    <section class="admin-panel" aria-labelledby="user-results-title">
        <div class="admin-panel-heading">
            <h2 id="user-results-title">Accounts</h2>
            <span class="admin-muted">{{ $users->total() }} {{ $users->total() === 1 ? 'account' : 'accounts' }}</span>
        </div>

        @if ($users->isEmpty())
            <div class="activity-empty" role="status">
                <span class="empty-mark" aria-hidden="true">—</span>
                <p>No accounts match these filters.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Store</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Registered</th>
                        <th scope="col">Last login</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $account)
                        <tr>
                            <td data-label="ID">{{ $account->id }}</td>
                            <td data-label="Name">
                                <a href="{{ route('admin.users.show', $account) }}">{{ $account->name }}</a>
                                <br><span class="admin-muted">{{ $account->username }}</span>
                            </td>
                            <td data-label="Email">{{ $account->email }}</td>
                            <td data-label="Store">{{ $account->store?->store_name ?? '—' }}</td>
                            <td data-label="Role">{{ $account->isSuperAdmin() ? 'Super Admin' : 'Store owner' }}</td>
                            <td data-label="Status">
                                <span class="admin-status {{ $account->isActive() ? '' : 'is-warning' }}">{{ $account->isActive() ? 'Active' : 'Deactivated' }}</span>
                            </td>
                            <td data-label="Registered">{{ $account->created_at->format('M j, Y') }}</td>
                            <td data-label="Last login">{{ $account->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="admin-pagination">{{ $users->links() }}</div>
        @endif
    </section>
@endsection
