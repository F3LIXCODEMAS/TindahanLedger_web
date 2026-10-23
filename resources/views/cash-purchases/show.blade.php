<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash Purchase | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    @include('cash-purchases.partials.topbar')

    <main class="password-content credit-page-content">
        <section class="credit-confirmation-panel" aria-labelledby="cash-purchase-title">
            <span class="credit-confirmation-mark" aria-hidden="true">✓</span>
            <p class="eyebrow">CASH PURCHASE</p>
            <h1 id="cash-purchase-title">Cash Purchase Recorded</h1>
            <p class="credit-confirmation-copy">Paid in cash. No customer balance, credit, or Suki Points changed.</p>

            <dl class="credit-confirmation-details">
                <div>
                    <dt>Customer</dt>
                    <dd>{{ $purchase->customer?->full_name ?? 'Walk-in customer' }}</dd>
                </div>
                <div>
                    <dt>Date and time</dt>
                    <dd>{{ $purchase->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd>₱{{ $purchase->referenceSubtotal() }}</dd>
                </div>
                @if ($purchase->recordedBy)
                    <div>
                        <dt>Recorded by</dt>
                        <dd>{{ $purchase->recordedBy->name }}</dd>
                    </div>
                @endif
            </dl>

            <section class="credit-confirmation-items" aria-labelledby="cash-purchase-items-title">
                <h2 id="cash-purchase-items-title">Products</h2>
                <ul>
                    @foreach ($purchase->items as $purchaseItem)
                        <li>{{ $purchaseItem->description() }}</li>
                    @endforeach
                </ul>
            </section>

            <div class="credit-confirmation-actions">
                <a class="login-submit" href="{{ route('cash-purchases.create') }}">Record Another Cash Purchase</a>
                <a class="customer-ledger-link" href="{{ route('dashboard') }}">Back to Dashboard</a>
            </div>
        </section>
    </main>
</body>
</html>
