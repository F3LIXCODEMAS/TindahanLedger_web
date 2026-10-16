<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Super Admin | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('admin.dashboard') }}" aria-label="TindahanLedger Super Admin dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>

        <nav class="owner-navigation" aria-label="Super Admin navigation">
            <a class="owner-nav-link is-current" href="{{ route('admin.dashboard') }}" aria-current="page">Dashboard</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content">
        <header class="dashboard-heading">
            <p class="eyebrow">PLATFORM ADMINISTRATION</p>
            <h1>Super Admin Dashboard</h1>
            <p>Store, user, transaction, and analytics management will appear here.</p>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif
    </main>
</body>
</html>
