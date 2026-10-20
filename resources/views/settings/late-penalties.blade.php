<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Late Payment Policy | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <a class="owner-nav-link" href="{{ route('email-settings.edit') }}">Email notifications</a>
            <a class="owner-nav-link is-current" href="{{ route('late-penalties.edit') }}" aria-current="page">Late payment policy</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="email-settings-content">
        <a class="back-link" href="{{ route('dashboard') }}">← Back to dashboard</a>

        <header class="email-settings-heading">
            <p class="eyebrow">STORE SETTINGS</p>
            <h1>Late payment policy</h1>
            <p>Charge a one-time fee on a credit that is still unpaid after its deadline and grace period.</p>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        <p class="penalty-legal-note" role="note">
            Check that your fee and policy are allowed under the law and agreed with your customers before turning this on.
        </p>

        <section class="email-settings-panel" aria-labelledby="policy-title">
            <div class="form-heading">
                <h2 id="policy-title">Policy</h2>
                <p>
                    @if ($policy)
                        Version {{ $policy->version }}, last changed {{ $policy->updated_at->format('M j, Y g:i A') }}{{ $policy->updatedBy ? ' by '.$policy->updatedBy->name : '' }}.
                    @else
                        Not set up yet. Penalties are off.
                    @endif
                </p>
            </div>

            @if ($errors->any())
                <div class="password-error-summary" role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form class="password-form" method="POST" action="{{ route('late-penalties.update') }}">
                @csrf
                @method('PUT')

                <label class="penalty-acknowledge">
                    <input type="hidden" name="is_enabled" value="0">
                    <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $policy?->is_enabled ?? false))>
                    Charge late payment fees on new credits
                </label>

                <div class="form-field">
                    <label for="fee">Late fee per credit (₱)</label>
                    <input class="auth-input" id="fee" name="fee" type="text" inputmode="decimal" value="{{ old('fee', $fee) }}" required aria-describedby="fee-help">
                    <p class="password-requirements" id="fee-help">A one-time fixed fee per overdue credit. Maximum ₱{{ $maxFee }}.</p>
                </div>

                <div class="form-field">
                    <label for="grace_days">Grace period (calendar days after the deadline)</label>
                    <input class="auth-input" id="grace_days" name="grace_days" type="number" min="0" max="{{ $maxGraceDays }}" step="1" value="{{ old('grace_days', $graceDays) }}" required aria-describedby="grace-help">
                    <p class="password-requirements" id="grace-help">Example: deadline Oct 10 with 3 grace days means Oct 11–13 are free; the fee may be added from Oct 14 if a balance remains.</p>
                </div>

                <div class="form-field">
                    <label for="effective_from">Applies to credits recorded from</label>
                    <input class="auth-input" id="effective_from" name="effective_from" type="date" value="{{ old('effective_from', $policy?->effective_from?->toDateString() ?? now()->toDateString()) }}" required>
                </div>

                <div class="form-field">
                    <label for="disclosure_text">Extra policy note shown when recording a credit <span class="credit-optional">Optional</span></label>
                    <textarea class="auth-input credit-textarea" id="disclosure_text" name="disclosure_text" maxlength="1000" rows="3">{{ old('disclosure_text', $policy?->disclosure_text) }}</textarea>
                </div>

                <p class="password-requirements">
                    Changes only apply to credits recorded afterwards. Credits already recorded keep the fee and grace period they were accepted under, and credits recorded before the policy was turned on are never charged.
                </p>

                <button class="login-submit" type="submit">Save policy</button>
            </form>
        </section>
    </main>
</body>
</html>
