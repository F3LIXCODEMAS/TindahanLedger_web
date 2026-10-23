<header class="ledger-topbar">
    <a class="brand-lockup ledger-brand" href="{{ route('dashboard') }}" aria-label="TindahanLedger dashboard">
        <span class="brand-mark" aria-hidden="true">TL</span>
        <span class="brand-name">TindahanLedger</span>
    </a>
    <nav class="owner-navigation" aria-label="Owner navigation">
        <a class="owner-nav-link" href="{{ route('dashboard') }}">Dashboard</a>
        <a class="owner-nav-link" href="{{ route('customers.index') }}">Customers</a>
        <a class="owner-nav-link" href="{{ route('products.index') }}">Products</a>
        <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="logout-button" type="submit">Log out</button>
        </form>
    </nav>
</header>
