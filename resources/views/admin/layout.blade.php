<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | Super Admin | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    @php($activeNav ??= '')
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('admin.dashboard') }}" aria-label="TindahanLedger Super Admin dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>

        <nav class="owner-navigation" aria-label="Super Admin navigation">
            @foreach ([
                'dashboard' => ['Dashboard', route('admin.dashboard')],
                'stores' => ['Stores', route('admin.stores.index')],
                'users' => ['Users', route('admin.users.index')],
            ] as $navKey => [$navLabel, $navUrl])
                <a class="owner-nav-link {{ $activeNav === $navKey ? 'is-current' : '' }}" href="{{ $navUrl }}" @if ($activeNav === $navKey) aria-current="page" @endif>{{ $navLabel }}</a>
            @endforeach
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content">
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

        @yield('content')
    </main>
</body>
</html>
