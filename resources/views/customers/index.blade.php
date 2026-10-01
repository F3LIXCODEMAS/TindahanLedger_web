<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customers | {{ config('app.name', 'TindahanLedger') }}</title>
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

    <main class="dashboard-content">
        <div class="section-heading">
            <div>
                <p class="eyebrow">CUSTOMER RECORDS</p>
                <h1>Customers</h1>
            </div>
            <div class="customer-index-actions">
                <a class="customer-ledger-link" href="{{ route('credits.create') }}">Record Credit</a>
                <a class="login-submit customer-add-link" href="{{ route('customers.create') }}">Add customer</a>
            </div>
        </div>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        @if ($customers->isEmpty())
            <div class="activity-empty customer-empty" role="status">
                <p>No customers have been added yet.</p>
                <a href="{{ route('customers.create') }}">Add the first customer</a>
            </div>
        @else
            <ul class="customer-list">
                @foreach ($customers as $customer)
                    <li class="customer-record">
                        <div class="customer-record-main">
                            <div>
                                <p class="eyebrow">CUSTOMER</p>
                                <h2>{{ $customer->full_name }}</h2>
                            </div>
                            <div class="customer-record-actions">
                                <a class="customer-ledger-link" href="{{ route('customers.show', $customer) }}">Open ledger</a>
                                <a class="customer-edit-link" href="{{ route('customers.edit', $customer) }}">Edit details</a>
                            </div>
                        </div>

                        <dl class="customer-record-details">
                            <div>
                                <dt>Contact</dt>
                                <dd>{{ $customer->contact_number }}</dd>
                            </div>
                            <div class="customer-email-detail">
                                <dt>Email</dt>
                                <dd>{{ $customer->email }}</dd>
                            </div>
                            <div>
                                <dt>Credit limit</dt>
                                <dd>₱{{ $customer->credit_limit }}</dd>
                            </div>
                            <div>
                                <dt>Outstanding</dt>
                                <dd class="customer-outstanding">₱{{ $customer->outstanding_balance }}</dd>
                            </div>
                            <div class="customer-landmark-detail">
                                <dt>Landmark</dt>
                                <dd>{{ $customer->residential_landmark }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>

            {{ $customers->links() }}
        @endif
    </main>
</body>
</html>
