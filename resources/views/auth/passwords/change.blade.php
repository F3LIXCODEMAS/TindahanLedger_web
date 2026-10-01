<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Change Password | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="password-content">
        <a class="back-link" href="{{ route('dashboard') }}">← Back to dashboard</a>

        <section class="password-panel" aria-labelledby="password-title">
            <div class="form-heading">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h1 id="password-title">Change password</h1>
                <p>Choose a new password for your store owner account.</p>
            </div>

            @if (session('status'))
                <p class="status-banner" role="status">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <div class="password-error-summary" role="alert" aria-live="polite">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form class="password-form" method="POST" action="{{ route('password.update') }}">
                @csrf
                @method('PUT')

                <div class="form-field">
                    <label for="current_password">Current password</label>
                    <input
                        class="auth-input"
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                        @if ($errors->has('current_password')) aria-invalid="true" @endif
                        aria-describedby="current-password-error"
                    >
                    <p class="field-error" id="current-password-error" role="alert" @if (!$errors->has('current_password')) hidden @endif>@error('current_password'){{ $message }}@enderror</p>
                </div>

                <div class="form-field">
                    <label for="password">New password</label>
                    <input
                        class="auth-input"
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        minlength="12"
                        required
                        @if ($errors->has('password')) aria-invalid="true" @endif
                        aria-describedby="password-requirements password-error"
                    >
                    <p class="password-requirements" id="password-requirements">At least 12 characters, including letters and numbers. Do not reuse your current password.</p>
                    <p class="field-error" id="password-error" role="alert" @if (!$errors->has('password')) hidden @endif>@error('password'){{ $message }}@enderror</p>
                </div>

                <div class="form-field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input
                        class="auth-input"
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        minlength="12"
                        required
                    >
                </div>

                <button class="login-submit" type="submit">Update password</button>
            </form>
        </section>
    </main>
</body>
</html>
