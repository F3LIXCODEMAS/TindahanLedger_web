<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $customer->full_name }} | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content customer-ledger-content">
        <a class="back-link" href="{{ route('customers.index') }}">Back to customers</a>

        <header class="customer-ledger-heading">
            <div>
                <p class="eyebrow">CUSTOMER LEDGER</p>
                <h1>{{ $customer->full_name }}</h1>
            </div>
            <a class="customer-edit-link" href="{{ route('customers.edit', $customer) }}">Edit details</a>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        @if ($ledger['is_overdue'])
            <p class="ledger-overdue-banner" role="status">
                {{ $ledger['overdue_entry_count'] }} overdue {{ $ledger['overdue_entry_count'] === 1 ? 'transaction' : 'transactions' }}
            </p>
        @endif

        @include('customers.partials.ledger-balances')

        <section class="customer-ledger-section" aria-labelledby="customer-information-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">PROFILE</p>
                    <h2 id="customer-information-title">Customer information</h2>
                </div>
                @if ($ledger['is_overdue'])
                    <span class="ledger-status is-overdue">Overdue</span>
                @else
                    <span class="ledger-status is-current">Current</span>
                @endif
            </div>
            <dl class="customer-ledger-details">
                <div>
                    <dt>Full name</dt>
                    <dd>{{ $customer->full_name }}</dd>
                </div>
                <div>
                    <dt>Contact number</dt>
                    <dd>{{ $customer->contact_number }}</dd>
                </div>
                <div>
                    <dt>Email address</dt>
                    <dd>{{ $customer->email }}</dd>
                </div>
                <div>
                    <dt>Residential landmark</dt>
                    <dd>{{ $customer->residential_landmark }}</dd>
                </div>
            </dl>
        </section>

        <section class="customer-ledger-section" aria-labelledby="quick-actions-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">ACTIONS</p>
                    <h2 id="quick-actions-title">Quick actions</h2>
                </div>
            </div>
            <div class="customer-ledger-actions">
                <a class="ledger-action-button is-credit-action" href="{{ route('credits.create', ['customer_id' => $customer->id]) }}">Record Credit</a>
                @if ($ledger['outstanding_balance'] === '0.00')
                    <button class="ledger-action-button is-payment-action" type="button" disabled aria-disabled="true">No Balance to Pay</button>
                @else
                    <a class="ledger-action-button is-payment-action" href="{{ route('payments.create', ['customer_id' => $customer->id]) }}">Record Payment</a>
                @endif
                <a class="ledger-action-button" href="{{ route('customers.access-code.show', $customer) }}">Customer QR access</a>
            </div>
        </section>

        @include('customers.partials.suki-points')

        @include('customers.partials.ledger-history')
    </main>
</body>
</html>
