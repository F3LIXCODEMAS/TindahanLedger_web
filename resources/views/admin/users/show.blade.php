@extends('admin.layout', ['activeNav' => 'users'])

@section('title', $account->name)

@section('content')
    <a class="back-link" href="{{ route('admin.users.index') }}">← Back to users</a>

    <header class="dashboard-heading">
        <p class="eyebrow">ACCOUNT #{{ $account->id }}</p>
        <h1>{{ $account->name }}</h1>
        <p>
            <span class="admin-status {{ $account->isActive() ? '' : 'is-warning' }}">{{ $account->isActive() ? 'Active' : 'Deactivated' }}</span>
            {{ $account->isSuperAdmin() ? 'Super Admin' : 'Store owner' }}
        </p>
    </header>

    <section class="admin-panel" aria-labelledby="account-details-title">
        <h2 id="account-details-title">Details</h2>
        <dl class="admin-detail-grid">
            <div><dt>Username</dt><dd>{{ $account->username }}</dd></div>
            <div><dt>Email</dt><dd>{{ $account->email }}</dd></div>
            <div>
                <dt>Assigned store</dt>
                <dd>
                    @if ($account->store)
                        <a href="{{ route('admin.stores.show', $account->store) }}">{{ $account->store->store_name }}</a>
                    @else
                        Not assigned
                    @endif
                </dd>
            </div>
            <div><dt>Registered</dt><dd>{{ $account->created_at->format('M j, Y') }}</dd></div>
            <div><dt>Last login</dt><dd>{{ $account->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</dd></div>
            <div><dt>Password</dt><dd>{{ $account->must_change_password ? 'Temporary — must be changed at next sign-in' : 'Set by the owner' }}</dd></div>
        </dl>
    </section>

    @if ($account->isStoreOwner() && ! $isOwnAccount)
        <section class="admin-panel" aria-labelledby="account-access-title">
            <h2 id="account-access-title">Account access</h2>
            @if ($account->isActive())
                <p class="admin-muted">Deactivating signs the owner out and blocks sign-in. Their store, customers, and transactions are kept unchanged.</p>
                <form class="admin-inline-form" method="POST" action="{{ route('admin.users.status.update', $account) }}" data-confirm="Deactivate {{ $account->name }}? They will be signed out and unable to sign in.">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="inactive">
                    <button class="admin-button-danger" type="submit">Deactivate account</button>
                </form>
            @else
                <form class="admin-inline-form" method="POST" action="{{ route('admin.users.status.update', $account) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="active">
                    <button class="admin-button" type="submit">Reactivate account</button>
                </form>
            @endif
        </section>

        <section class="admin-panel" aria-labelledby="temporary-password-title">
            <h2 id="temporary-password-title">Issue temporary password</h2>
            <p class="admin-muted">Use this when the owner cannot sign in. Their current password stops working and saved "remember me" sign-ins are cleared. To end an open session immediately, deactivate the account.</p>
            <form class="admin-form" method="POST" action="{{ route('admin.users.password.update', $account) }}" data-confirm="Replace {{ $account->name }}'s password with a temporary one?">
                @csrf
                @method('PUT')
                @include('admin.users.partials.temporary-password-fields')
                <button class="admin-button-secondary" type="submit">Issue temporary password</button>
            </form>
        </section>
    @endif

    @include('admin.partials.audit-log', ['auditLogs' => $auditLogs])
@endsection
