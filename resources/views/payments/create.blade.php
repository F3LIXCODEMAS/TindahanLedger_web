<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Payment | {{ config('app.name', 'TindahanLedger') }}</title>
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

    <main class="password-content credit-page-content">
        <a class="back-link" href="{{ $selectedCustomerId ? route('customers.show', $selectedCustomerId) : route('customers.index') }}">Back to {{ $selectedCustomerId ? 'customer ledger' : 'customers' }}</a>

        <section class="password-panel payment-panel" aria-labelledby="payment-form-title">
            <div class="form-heading">
                <p class="eyebrow">CUSTOMER LEDGER</p>
                <h1 id="payment-form-title">Record Payment</h1>
                <p>Record the cash received against the customer's outstanding balance.</p>
            </div>

            @if ($customers->isEmpty())
                <div class="credit-empty-state" role="status">
                    <h2>No customers available.</h2>
                    <p>Add a customer before recording payment.</p>
                    <a class="login-submit" href="{{ route('customers.create') }}">Add customer</a>
                </div>
            @else
                <form class="credit-form" method="POST" action="{{ route('payments.store') }}" data-payment-form>
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                    @if ($errors->any())
                        <div class="password-error-summary" role="alert" aria-live="polite">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="form-field">
                        <label for="payment-customer-search">Search customer</label>
                        <input class="auth-input" id="payment-customer-search" type="search" autocomplete="off" placeholder="Name or contact number" data-payment-customer-search aria-describedby="payment-customer-search-status">
                        <p class="credit-search-status" id="payment-customer-search-status" aria-live="polite" data-payment-search-status></p>
                    </div>

                    <div class="form-field">
                        <label for="customer_id">Customer <span aria-hidden="true">*</span></label>
                        <select class="auth-input credit-select" id="customer_id" name="customer_id" required aria-describedby="payment-customer-error">
                            <option value="">Select a customer</option>
                            @foreach ($customers as $customerOption)
                                <option
                                    value="{{ $customerOption->id }}"
                                    data-payment-customer-option
                                    data-summary-url="{{ route('payments.customer-summary', $customerOption) }}"
                                    @selected((string) old('customer_id', $selectedCustomerId) === (string) $customerOption->id)
                                >{{ $customerOption->full_name }} · {{ $customerOption->contact_number }}</option>
                            @endforeach
                        </select>
                        <p class="field-error" id="payment-customer-error" role="alert" @if (!$errors->has('customer_id')) hidden @endif>@error('customer_id'){{ $message }}@enderror</p>
                    </div>

                    <section class="payment-balance-summary" aria-label="Selected customer outstanding balance" aria-live="polite" data-payment-summary>
                        <p class="eyebrow">OUTSTANDING BALANCE</p>
                        <p class="payment-balance-value" data-payment-balance>₱{{ $selectedSummary['outstanding_balance'] ?? '—' }}</p>
                        <p class="payment-zero-message" data-payment-zero-message @if (!$selectedSummary || $selectedSummary['outstanding_balance'] !== '0.00') hidden @endif>No outstanding balance to pay.</p>
                    </section>

                    <div class="form-field">
                        <label for="amount">Payment amount <span aria-hidden="true">*</span></label>
                        <div class="credit-amount-field">
                            <span aria-hidden="true">₱</span>
                            <input class="auth-input" id="amount" name="amount" type="number" value="{{ old('amount') }}" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="500.00" required aria-describedby="payment-amount-error" @disabled($selectedSummary && $selectedSummary['outstanding_balance'] === '0.00') @if ($errors->has('amount')) aria-invalid="true" @endif>
                        </div>
                        <p class="field-error" id="payment-amount-error" role="alert" @if (!$errors->has('amount')) hidden @endif>@error('amount'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="note">Note <span class="credit-optional">Optional</span></label>
                        <textarea class="auth-input credit-textarea" id="note" name="note" maxlength="500" rows="2" placeholder="Paid in cash" @if ($errors->has('note')) aria-invalid="true" @endif aria-describedby="payment-note-error">{{ old('note') }}</textarea>
                        <p class="field-error" id="payment-note-error" role="alert" @if (!$errors->has('note')) hidden @endif>@error('note'){{ $message }}@enderror</p>
                    </div>

                    <div class="customer-form-actions credit-form-actions">
                        <a class="customer-cancel-link" href="{{ $selectedCustomerId ? route('customers.show', $selectedCustomerId) : route('customers.index') }}">Cancel</a>
                        <button class="login-submit" type="submit" data-payment-submit @disabled($selectedSummary && $selectedSummary['outstanding_balance'] === '0.00')>
                            <span data-payment-submit-label>Save Payment</span>
                            <span data-payment-saving-label hidden>Saving...</span>
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
