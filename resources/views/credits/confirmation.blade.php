<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Credit recorded | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <a class="owner-nav-link is-current" href="{{ route('customers.index') }}" aria-current="page">Customers</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="password-content credit-page-content">
        <section class="credit-confirmation-panel" aria-labelledby="credit-confirmation-title">
            <span class="credit-confirmation-mark" aria-hidden="true">✓</span>
            <p class="eyebrow">TRANSACTION SAVED</p>
            <h1 id="credit-confirmation-title">Credit Recorded</h1>
            <p class="credit-confirmation-copy">The credit has been added to the customer ledger.</p>

            <dl class="credit-confirmation-details">
                <div>
                    <dt>Customer</dt>
                    <dd>{{ $customer->full_name }}</dd>
                </div>
                <div>
                    <dt>Credit</dt>
                    <dd>₱{{ $ledgerEntry->amount }}</dd>
                </div>
                <div>
                    <dt>New balance</dt>
                    <dd>₱{{ $newBalance }}</dd>
                </div>
            </dl>

            <div class="credit-confirmation-actions">
                <a class="login-submit" href="{{ route('customers.show', $customer) }}">View Customer Ledger</a>
                <a class="customer-ledger-link" href="{{ route('credits.create', ['customer_id' => $customer->id]) }}">Record Another Credit</a>
            </div>
        </section>
    </main>
</body>
</html>
