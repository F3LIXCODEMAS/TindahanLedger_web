<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    @include('products.partials.topbar', ['isProductList' => true])

    <main class="dashboard-content">
        <div class="section-heading">
            <div>
                <p class="eyebrow">PRODUCT CATALOG</p>
                <h1>Products</h1>
            </div>
            <div class="customer-index-actions">
                <a class="login-submit customer-add-link" href="{{ route('products.create') }}">Add product</a>
            </div>
        </div>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        <form class="overdue-controls" method="GET" action="{{ route('products.index') }}" role="search">
            <div class="overdue-search-field">
                <label for="product-search">Search product</label>
                <input id="product-search" name="search" type="search" value="{{ $search }}" maxlength="120" placeholder="Enter a product name">
            </div>
            <div class="overdue-sort-field">
                <label for="product-status">Status</label>
                <select id="product-status" name="status">
                    <option value="" @selected($status === '')>All products</option>
                    <option value="{{ \App\Models\Product::STATUS_ACTIVE }}" @selected($status === \App\Models\Product::STATUS_ACTIVE)>Active only</option>
                    <option value="{{ \App\Models\Product::STATUS_INACTIVE }}" @selected($status === \App\Models\Product::STATUS_INACTIVE)>Inactive only</option>
                </select>
            </div>
            <button class="overdue-filter-button" type="submit">Apply</button>
        </form>

        @if ($products->isEmpty())
            <div class="activity-empty customer-empty" role="status">
                @if ($search === '' && $status === '')
                    <p>No products have been added yet.</p>
                    <a href="{{ route('products.create') }}">Add the first product</a>
                @else
                    <p>No products match these filters.</p>
                    <a href="{{ route('products.index') }}">Clear search and filters</a>
                @endif
            </div>
        @else
            <ul class="customer-list">
                @foreach ($products as $product)
                    <li class="customer-record">
                        <div class="customer-record-main">
                            <div>
                                <p class="eyebrow">PRODUCT</p>
                                <h2>{{ $product->name }}</h2>
                                <span class="admin-status {{ $product->isActive() ? '' : 'is-warning' }}">{{ $product->isActive() ? 'Active' : 'Inactive' }}</span>
                            </div>
                            <div class="customer-record-actions">
                                <a class="customer-edit-link" href="{{ route('products.edit', $product) }}">Edit</a>
                                <form method="POST" action="{{ route('products.status.update', $product) }}" @if ($product->isActive()) data-confirm="Deactivate {{ $product->name }}? It stays in your records but cannot be chosen for new purchases until you reactivate it." @endif>
                                    @csrf
                                    @method('PATCH')
                                    @if ($product->isActive())
                                        <input type="hidden" name="status" value="{{ \App\Models\Product::STATUS_INACTIVE }}">
                                        <button class="ledger-action-button" type="submit">Deactivate</button>
                                    @else
                                        <input type="hidden" name="status" value="{{ \App\Models\Product::STATUS_ACTIVE }}">
                                        <button class="ledger-action-button" type="submit">Reactivate</button>
                                    @endif
                                </form>
                            </div>
                        </div>

                        <dl class="customer-record-details">
                            <div>
                                <dt>Unit price</dt>
                                <dd>{{ $product->unit_price === null ? 'Not set' : '₱'.$product->unit_price }}</dd>
                            </div>
                            @if ($product->description !== null)
                                <div class="product-description-detail">
                                    <dt>Description</dt>
                                    <dd>{{ $product->description }}</dd>
                                </div>
                            @endif
                        </dl>
                    </li>
                @endforeach
            </ul>

            {{ $products->links() }}
        @endif
    </main>
</body>
</html>
