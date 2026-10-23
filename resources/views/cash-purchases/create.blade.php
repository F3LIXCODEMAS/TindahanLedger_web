<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Cash Purchase | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    @include('cash-purchases.partials.topbar')

    <main class="password-content credit-page-content">
        <a class="back-link" href="{{ route('dashboard') }}">← Back to dashboard</a>

        <section class="password-panel credit-panel" aria-labelledby="cash-purchase-title">
            <div class="form-heading">
                <p class="eyebrow">CASH PURCHASE</p>
                <h1 id="cash-purchase-title">Record Cash Purchase</h1>
                <p>For products paid in full right now. This is not credit and not a repayment, so no customer balance changes.</p>
            </div>

            <form class="credit-form" method="POST" action="{{ route('cash-purchases.store') }}">
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
                    <label for="customer_id">Customer <span class="credit-optional">Optional</span></label>
                    <select class="auth-input credit-select" id="customer_id" name="customer_id" aria-describedby="cash-customer-help customer-error">
                        <option value="">Walk-in customer (no record)</option>
                        @foreach ($customers as $customerOption)
                            <option value="{{ $customerOption->id }}" @selected((string) old('customer_id') === (string) $customerOption->id)>{{ $customerOption->full_name }} · {{ $customerOption->contact_number }}</option>
                        @endforeach
                    </select>
                    <p class="password-requirements" id="cash-customer-help">Choose a customer only to keep this sale in their purchase history. Their balance is not affected.</p>
                    <p class="field-error" id="customer-error" role="alert" @if (!$errors->has('customer_id')) hidden @endif>@error('customer_id'){{ $message }}@enderror</p>
                </div>

                @include('credits.partials.product-picker', ['mode' => 'cash', 'selectedItems' => $selectedItems])

                <div class="customer-form-actions credit-form-actions">
                    <a class="customer-cancel-link" href="{{ route('dashboard') }}">Cancel</a>
                    <button class="login-submit" type="submit">Record cash purchase</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
