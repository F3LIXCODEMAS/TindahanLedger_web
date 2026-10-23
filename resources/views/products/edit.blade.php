<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit product | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    @include('products.partials.topbar')

    <main class="password-content">
        <a class="back-link" href="{{ route('products.index') }}">← Back to products</a>
        <section class="password-panel" aria-labelledby="product-form-title">
            <div class="form-heading">
                <p class="eyebrow">PRODUCT CATALOG</p>
                <h1 id="product-form-title">Edit product</h1>
                <p>Update the name, price, or description. The product keeps its place in your records.</p>
            </div>
            @include('products.form')
        </section>
    </main>
</body>
</html>
