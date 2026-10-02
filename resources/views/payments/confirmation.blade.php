<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment recorded | {{ config('app.name', 'TindahanLedger') }}</title>
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
        <section class="credit-confirmation-panel" aria-labelledby="payment-confirmation-title">
            <span class="credit-confirmation-mark" aria-hidden="true">✓</span>
            <p class="eyebrow">TRANSACTION SAVED</p>
            <h1 id="payment-confirmation-title">Payment Recorded</h1>
            <p class="credit-confirmation-copy">The payment has been applied to the customer's oldest outstanding credits first.</p>

            <dl class="credit-confirmation-details">
                <div>
                    <dt>Customer</dt>
                    <dd>{{ $receipt['customer_name'] }}</dd>
                </div>
                <div>
                    <dt>Payment</dt>
                    <dd>₱{{ $receipt['total_amount'] }}</dd>
                </div>
                <div>
                    <dt>Previous balance</dt>
                    <dd>₱{{ $receipt['previous_balance'] }}</dd>
                </div>
                <div>
                    <dt>Remaining balance</dt>
                    <dd>₱{{ $receipt['remaining_balance'] }}</dd>
                </div>
            </dl>

            @if ($receipt['remaining_balance'] === '0.00')
                <p class="payment-debt-cleared" role="status">Debt fully paid</p>
            @endif

            <div class="credit-confirmation-actions">
                <a class="login-submit" href="{{ route('customers.show', $receipt['customer_id']) }}">View Customer Ledger</a>
                @if ($receipt['remaining_balance'] !== '0.00')
                    <a class="customer-ledger-link" href="{{ route('payments.create', ['customer_id' => $receipt['customer_id']]) }}">Record Another Payment</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
