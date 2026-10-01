<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Owner Sign In | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="owner-auth-page">
    <main class="auth-shell">
        <div class="auth-panel">
            <section class="auth-intro" aria-labelledby="brand-title">
                <a class="brand-lockup" href="{{ route('login') }}" aria-label="TindahanLedger owner sign in">
                    <span class="brand-mark" aria-hidden="true">TL</span>
                    <span class="brand-name">TindahanLedger</span>
                </a>

                <div class="intro-copy">
                    <p class="eyebrow intro-eyebrow">STORE OWNER ACCESS</p>
                    <h1 id="brand-title">A clearer view of every balance.</h1>
                    <p>Keep customer credit, repayments, and daily ledger activity in one secure place.</p>
                </div>

                <div class="intro-footnote">
                    <span class="status-mark" aria-hidden="true"></span>
                    <span>Private access for your store</span>
                </div>
            </section>

            <section class="auth-form-section" aria-labelledby="login-title">
                <div class="form-heading">
                    <p class="eyebrow">OWNER SIGN IN</p>
                    <h2 id="login-title">Welcome back</h2>
                    <p>Sign in with your store owner account.</p>
                </div>

                <form class="login-form" method="POST" action="{{ route('login.store') }}" data-login-form>
                    @csrf

                    <div class="form-field">
                        <label for="email">Email address</label>
                        <input
                            class="auth-input"
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            inputmode="email"
                            placeholder="owner@example.com"
                            required
                            autofocus
                            @if ($errors->has('email')) aria-invalid="true" @endif
                            aria-describedby="email-error"
                        >
                        <p class="field-error" id="email-error" role="alert" @if (!$errors->has('email')) hidden @endif>@error('email'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input
                                class="auth-input"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                                @if ($errors->has('password')) aria-invalid="true" @endif
                                aria-describedby="password-error"
                            >
                            <button
                                class="password-toggle"
                                type="button"
                                data-password-toggle
                                aria-controls="password"
                                aria-pressed="false"
                                aria-label="Show password"
                            >Show</button>
                        </div>
                        <p class="field-error" id="password-error" role="alert" @if (!$errors->has('password')) hidden @endif>@error('password'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-options">
                        <label class="remember-option" for="remember">
                            <input id="remember" name="remember" type="checkbox" value="1">
                            <span>Remember me</span>
                        </label>
                    </div>

                    <button class="login-submit" type="submit" data-login-submit>
                        <span data-submit-label>Log In</span>
                        <span data-loading-label hidden>Logging in...</span>
                    </button>
                </form>

                <p class="form-footer">Your store information is available only to authorized owners.</p>
            </section>
        </div>
    </main>
</body>
</html>
