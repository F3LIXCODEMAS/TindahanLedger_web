<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Access Card | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="access-card-page">
    <div class="access-card-actions">
        <button class="admin-button" type="button" data-print-page>Print card</button>
        <a class="admin-button-secondary" href="{{ route('customers.access-code.show', $customer) }}">Back</a>
    </div>

    <article class="access-card">
        <p class="eyebrow">{{ $customer->store->store_name }}</p>
        <h1>{{ $customer->full_name }}</h1>
        <div class="access-code-image">{!! $qrSvg !!}</div>
        <ol>
            <li>Open your phone camera and point it at the code.</li>
            <li>Tap the link that appears to see your balance and payment history.</li>
        </ol>
        <p class="access-card-warning">Keep this card private. Anyone who scans it can view your account. Ask the store for a new card if it is lost.</p>
    </article>
</body>
</html>
