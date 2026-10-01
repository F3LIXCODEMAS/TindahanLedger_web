<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Credit | {{ config('app.name', 'TindahanLedger') }}</title>
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

        <section class="password-panel credit-panel" aria-labelledby="credit-form-title">
            <div class="form-heading">
                <p class="eyebrow">CUSTOMER LEDGER</p>
                <h1 id="credit-form-title">Record Credit</h1>
                <p>Record the amount first. Items, note, and deadline are optional.</p>
            </div>

            @if ($customers->isEmpty())
                <div class="credit-empty-state" role="status">
                    <h2>No customers available.</h2>
                    <p>Add a customer before recording credit.</p>
                    <a class="login-submit" href="{{ route('customers.create') }}">Add customer</a>
                </div>
            @else
                <form class="credit-form" method="POST" action="{{ route('credits.store') }}" data-credit-form>
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
                        <label for="customer-search">Search customer</label>
                        <input class="auth-input" id="customer-search" type="search" autocomplete="off" placeholder="Name or contact number" data-credit-customer-search aria-describedby="customer-search-status">
                        <p class="credit-search-status" id="customer-search-status" aria-live="polite" data-credit-search-status></p>
                    </div>

                    <div class="form-field">
                        <label for="customer_id">Customer <span aria-hidden="true">*</span></label>
                        <select class="auth-input credit-select" id="customer_id" name="customer_id" required aria-describedby="customer-error">
                            <option value="">Select a customer</option>
                            @foreach ($customers as $customerOption)
                                <option
                                    value="{{ $customerOption->id }}"
                                    data-credit-customer-option
                                    data-summary-url="{{ route('credits.customer-summary', $customerOption) }}"
                                    @selected((string) old('customer_id', $selectedCustomerId) === (string) $customerOption->id)
                                >{{ $customerOption->full_name }} · {{ $customerOption->contact_number }}</option>
                            @endforeach
                        </select>
                        <p class="field-error" id="customer-error" role="alert" @if (!$errors->has('customer_id')) hidden @endif>@error('customer_id'){{ $message }}@enderror</p>
                    </div>

                    <section class="credit-financial-summary" aria-label="Selected customer credit position" aria-live="polite" data-credit-summary>
                        <div>
                            <span>Credit limit</span>
                            <strong data-credit-limit>₱{{ $selectedSummary['credit_limit'] ?? '—' }}</strong>
                        </div>
                        <div>
                            <span>Current balance</span>
                            <strong data-credit-balance>₱{{ $selectedSummary['outstanding_balance'] ?? '—' }}</strong>
                        </div>
                        <div>
                            <span>Available credit</span>
                            <strong data-credit-available>₱{{ $selectedSummary['available_credit'] ?? '—' }}</strong>
                        </div>
                    </section>

                    <div class="form-field">
                        <label for="amount">Amount <span aria-hidden="true">*</span></label>
                        <div class="credit-amount-field">
                            <span aria-hidden="true">₱</span>
                            <input class="auth-input" id="amount" name="amount" type="number" value="{{ old('amount') }}" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="500.00" required aria-describedby="amount-error" @if ($errors->has('amount')) aria-invalid="true" @endif>
                        </div>
                        <p class="field-error" id="amount-error" role="alert" @if (!$errors->has('amount')) hidden @endif>@error('amount'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="itemized_list">Items <span class="credit-optional">Optional</span></label>
                        <textarea class="auth-input credit-textarea" id="itemized_list" name="itemized_list" maxlength="2000" rows="2" placeholder="rice, sardines, softdrinks" @if ($errors->has('itemized_list')) aria-invalid="true" @endif aria-describedby="items-error">{{ old('itemized_list') }}</textarea>
                        <p class="field-error" id="items-error" role="alert" @if (!$errors->has('itemized_list')) hidden @endif>@error('itemized_list'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="note">Note <span class="credit-optional">Optional</span></label>
                        <textarea class="auth-input credit-textarea" id="note" name="note" maxlength="500" rows="2" placeholder="Customer will pay Friday" @if ($errors->has('note')) aria-invalid="true" @endif aria-describedby="note-error">{{ old('note') }}</textarea>
                        <p class="field-error" id="note-error" role="alert" @if (!$errors->has('note')) hidden @endif>@error('note'){{ $message }}@enderror</p>
                    </div>

                    <div class="form-field">
                        <label for="repayment_deadline">Repayment deadline <span class="credit-optional">Optional</span></label>
                        <input class="auth-input" id="repayment_deadline" name="repayment_deadline" type="date" value="{{ old('repayment_deadline') }}" aria-describedby="deadline-error" @if ($errors->has('repayment_deadline')) aria-invalid="true" @endif>
                        <p class="field-error" id="deadline-error" role="alert" @if (!$errors->has('repayment_deadline')) hidden @endif>@error('repayment_deadline'){{ $message }}@enderror</p>
                    </div>

                    <div class="customer-form-actions credit-form-actions">
                        <a class="customer-cancel-link" href="{{ $selectedCustomerId ? route('customers.show', $selectedCustomerId) : route('customers.index') }}">Cancel</a>
                        <button class="login-submit" type="submit" data-credit-submit>
                            <span data-credit-submit-label>Save Credit</span>
                            <span data-credit-saving-label hidden>Saving...</span>
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
