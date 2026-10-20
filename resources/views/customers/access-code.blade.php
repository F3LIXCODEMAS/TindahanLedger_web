<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>QR Access | {{ $customer->full_name }} | {{ config('app.name', 'TindahanLedger') }}</title>
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
        <a class="back-link" href="{{ route('customers.show', $customer) }}">Back to {{ $customer->full_name }}</a>

        <header class="customer-ledger-heading">
            <div>
                <p class="eyebrow">CUSTOMER PORTAL ACCESS</p>
                <h1>{{ $customer->full_name }}</h1>
            </div>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="password-error-summary" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <section class="admin-panel access-code-panel" aria-labelledby="access-code-title">
            <h2 id="access-code-title">QR code</h2>

            @if ($credential && $qrSvg)
                <p><span class="admin-status">Active</span> <span class="admin-muted">Created {{ $credential->created_at->format('M j, Y g:i A') }}{{ $credential->last_used_at ? ' · Last scanned '.$credential->last_used_at->format('M j, Y g:i A') : '' }}</span></p>

                <div class="access-code-image">{!! $qrSvg !!}</div>

                <p class="admin-muted">
                    Scanning this code opens {{ $customer->full_name }}'s read-only ledger without a password. Anyone holding the card or a copy of it can view that ledger, so give it only to the customer. If it is lost or shared, revoke or replace it.
                </p>

                <div class="admin-inline-form">
                    <a class="admin-button" href="{{ route('customers.access-code.card', $customer) }}" target="_blank" rel="noopener noreferrer">Print access card</a>
                    <a class="admin-button-secondary" href="{{ route('customers.access-code.download', $customer) }}">Download QR (SVG)</a>
                </div>
            @elseif ($credential)
                <p class="admin-muted">This QR code can no longer be displayed, for example after the application key changed. Create a new code to print a card.</p>
            @else
                <p class="admin-muted">This customer does not have an active QR code. Create one to give them read-only access to their ledger.</p>
            @endif
        </section>

        <section class="admin-panel" aria-labelledby="access-code-manage-title">
            <h2 id="access-code-manage-title">Manage access</h2>
            <div class="admin-inline-form">
                <form method="POST" action="{{ route('customers.access-code.store', $customer) }}" @if ($credential) data-confirm="Create a new QR code? The current code and any open portal sessions will stop working." @endif>
                    @csrf
                    <button class="admin-button{{ $credential ? '-secondary' : '' }}" type="submit">{{ $credential ? 'Replace QR code' : 'Create QR code' }}</button>
                </form>

                @if ($credential)
                    <form method="POST" action="{{ route('customers.access-code.destroy', $customer) }}" data-confirm="Revoke this QR code? The customer will not be able to open the portal until you create a new code.">
                        @csrf
                        @method('DELETE')
                        <button class="admin-button-danger" type="submit">Revoke QR code</button>
                    </form>
                @endif
            </div>
            <p class="admin-muted">Replacing or revoking a code never changes the customer's balance, credits, payments, or Suki Points.</p>
        </section>
    </main>
</body>
</html>
