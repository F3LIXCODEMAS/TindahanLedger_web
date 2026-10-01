<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit customer | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <a class="owner-nav-link" href="{{ route('customers.index') }}">Customers</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="password-content">
        <a class="back-link" href="{{ route('customers.index') }}">← Back to customers</a>
        <section class="password-panel" aria-labelledby="customer-form-title">
            <div class="form-heading">
                <p class="eyebrow">CUSTOMER RECORDS</p>
                <h1 id="customer-form-title">Edit customer</h1>
                <p>Update contact details and the owner-approved credit limit.</p>
            </div>
            @include('customers.form')
        </section>
    </main>
</body>
</html>
