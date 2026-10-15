<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Email Notifications | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <a class="owner-nav-link is-current" href="{{ route('email-settings.edit') }}" aria-current="page">Email notifications</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
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
            <h1>Email notifications</h1>
            <p>Connect your Gmail account so TindahanLedger can send email from your store.</p>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->has('email_test'))
            <p class="email-settings-error" role="alert">{{ $errors->first('email_test') }}</p>
        @endif

        <div class="email-settings-grid">
            <section class="email-settings-panel" aria-labelledby="settings-title">
                <div class="form-heading">
                    <h2 id="settings-title">Your sender details</h2>
                    <p>Your App Password is encrypted before it is saved. It is never shown again after saving.</p>
                </div>

                @if ($settings?->hasSmtpPassword())
                    <p class="email-connection-status" role="status">
                        <span class="email-connection-dot" aria-hidden="true"></span>
                        App Password saved
                    </p>
                @endif

                @if ($settings?->needsPasswordReentry())
                    <p class="email-settings-error" role="alert">
                        Your saved App Password can no longer be used. Please enter it again and save.
                    </p>
                @endif

                <form class="password-form" method="POST" action="{{ route('email-settings.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="form-field">
                        <label for="store_name">Store name</label>
                        <input
                            class="auth-input"
                            id="store_name"
                            name="store_name"
                            type="text"
                            value="{{ old('store_name', $settings?->store_name) }}"
                            autocomplete="organization"
                            maxlength="255"
                            required
                            @if ($errors->has('store_name')) aria-invalid="true" @endif
                            aria-describedby="store-name-error"
                        >
                        <p class="field-error" id="store-name-error" role="alert" @if (!$errors->has('store_name')) hidden @endif>@error('store_name'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="sender_email">Your Gmail address</label>
                        <input
                            class="auth-input"
                            id="sender_email"
                            name="sender_email"
                            type="email"
                            value="{{ old('sender_email', $settings?->sender_email) }}"
                            autocomplete="email"
                            maxlength="255"
                            required
                            @if ($errors->has('sender_email')) aria-invalid="true" @endif
                            aria-describedby="sender-email-help sender-email-error"
                        >
                        <p class="password-requirements" id="sender-email-help">This address sends the test email and appears as the sender. Use a Gmail or Google Workspace address; other email providers are not supported.</p>
                        <p class="field-error" id="sender-email-error" role="alert" @if (!$errors->has('sender_email')) hidden @endif>@error('sender_email'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="app_password">Google App Password</label>
                        <input
                            class="auth-input"
                            id="app_password"
                            name="app_password"
                            type="password"
                            autocomplete="new-password"
                            maxlength="255"
                            @if (!$settings?->hasSmtpPassword()) required @endif
                            @if ($errors->has('app_password')) aria-invalid="true" @endif
                            aria-describedby="app-password-help app-password-error"
                        >
                        <p class="password-requirements" id="app-password-help">
                            @if ($settings?->hasSmtpPassword())
                                Leave blank to keep your saved password. Enter a new one only if you want to replace it.
                            @else
                                Paste the App Password Google gives you. Spaces are removed automatically.
                            @endif
                        </p>
                        <p class="field-error" id="app-password-error" role="alert" @if (!$errors->has('app_password')) hidden @endif>@error('app_password'){{ $message }}@enderror</p>
                    </div>

                    <button class="login-submit" type="submit">Save email settings</button>
                </form>

                @if ($settings?->hasSmtpPassword())
                    <form class="email-test-form" method="POST" action="{{ route('email-settings.test') }}">
                        @csrf
                        <button class="email-test-button" type="submit">Test my email</button>
                        <p class="password-requirements">A test message will be sent to {{ $settings->sender_email }}.</p>
                    </form>
                @endif
            </section>

            <aside class="email-guide-panel" aria-labelledby="app-password-guide-title">
                <p class="eyebrow">QUICK SETUP</p>
                <h2 id="app-password-guide-title">Get a Google App Password</h2>
                <ol class="email-guide-steps">
                    <li>Open your Google Account and turn on <strong>2-Step Verification</strong> under Security.</li>
                    <li>Open <strong>App passwords</strong> in Security. Google may ask you to sign in again.</li>
                    <li>Create an app password, then paste it into the form here. Keep it private like your regular password.</li>
                </ol>
                <a class="email-guide-link" href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener noreferrer">
                    Open Google's App Password help
                    <span aria-hidden="true">↗</span>
                </a>
                <p class="email-guide-note">Google only shows an App Password once. If you lose it, create a new one and replace the saved password here.</p>
            </aside>
        </div>
    </main>
</body>
</html>
