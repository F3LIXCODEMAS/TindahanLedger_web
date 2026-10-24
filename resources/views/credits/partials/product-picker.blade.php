{{--
    Product picker shared by Record Credit (DUMS-36) and Record Cash Purchase (DUMS-37); driven by resources/js/app.js.
    $mode: 'credit' shows an optional reference subtotal compared with the credit amount.
           'cash' adds an editable unit price per item and a required total submitted as expected_total.
    $selectedItems: rows to restore after a validation error.
--}}
@php($isCashSale = $mode === 'cash')
<section class="credit-products" aria-labelledby="credit-products-title" data-credit-products data-mode="{{ $mode }}" data-search-url="{{ route('credits.products') }}">
    <div class="credit-products-heading">
        @if ($isCashSale)
            <h2 id="credit-products-title">Products sold</h2>
            <p class="password-requirements">Pick items from your catalog. Each price starts at the catalog price and can be changed for this sale only; the catalog is not updated. Quantities can have up to 3 decimal places, for example 0.25.</p>
        @else
            <h2 id="credit-products-title">Products <span class="credit-optional">Optional</span></h2>
            <p class="password-requirements">Pick items from your catalog. Quantities can have up to 3 decimal places, for example 0.25. The amount above stays what the customer owes; the item subtotal is only a reference.</p>
        @endif
    </div>

    <div class="form-field">
        <label for="credit-product-search">Search your products</label>
        <input class="auth-input" id="credit-product-search" type="search" autocomplete="off" maxlength="120" placeholder="Product name" data-credit-product-search aria-describedby="credit-product-status">
        <p class="credit-search-status" id="credit-product-status" aria-live="polite" data-credit-product-status></p>
        <ul class="credit-product-results" data-credit-product-results hidden></ul>
    </div>

    @php($itemErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => in_array($key, ['items', 'expected_total'], true) || str_starts_with($key, 'items.'))->flatten()->unique())
    <div class="field-error" id="credit-items-error" role="alert" @if ($itemErrors->isEmpty()) hidden @endif>
        @foreach ($itemErrors as $itemError)
            <p>{{ $itemError }}</p>
        @endforeach
    </div>

    <ul class="credit-item-list" data-credit-item-list data-next-index="{{ count($selectedItems) }}">
        @foreach ($selectedItems as $index => $selectedItem)
            @include('credits.partials.item-row', ['index' => $index, 'item' => $selectedItem, 'priceEditable' => $isCashSale])
        @endforeach
    </ul>

    {{-- Optional suggestions from this store's active association rules (DUMS-40). Adding one only adds a product row; nothing is submitted. --}}
    <section class="credit-recommendations" aria-labelledby="credit-recommendations-title" data-credit-recommendations data-recommendations-url="{{ route('credits.product-recommendations') }}" hidden>
        <h3 id="credit-recommendations-title">Often bought together</h3>
        <p class="credit-recommendations-note">Optional suggestions based on this store's recorded sales.</p>
        <p class="credit-search-status" aria-live="polite" data-credit-recommendation-status></p>
        <ul class="credit-recommendation-list" data-credit-recommendation-list hidden></ul>
    </section>

    <template data-credit-item-template>
        @include('credits.partials.item-row', ['index' => '__INDEX__', 'item' => null, 'priceEditable' => $isCashSale])
    </template>

    <div class="credit-item-summary" data-credit-item-summary @if ($selectedItems === []) hidden @endif>
        <p><span data-credit-item-subtotal-label>{{ $isCashSale ? 'Total' : 'Item subtotal (reference)' }}</span> <strong data-credit-item-subtotal>—</strong></p>
        <p class="credit-item-difference" data-credit-item-difference aria-live="polite"></p>
    </div>

    @if ($isCashSale)
        <input type="hidden" name="expected_total" value="" data-expected-total>
    @endif
</section>
