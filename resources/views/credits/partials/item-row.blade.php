{{--
    One selected product. Rendered by the server after a validation error and cloned from a <template> by resources/js/app.js.
    $priceEditable adds a sale-only unit price field (Record Cash Purchase); it starts at the catalog price.
--}}
@php($priceEditable ??= false)
<li class="credit-item-row {{ $priceEditable ? 'has-price-input' : '' }}" data-credit-item data-product-id="{{ $item['product_id'] ?? '' }}" data-unit-price="{{ $item['unit_price'] ?? '' }}">
    <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] ?? '' }}" data-credit-item-product>
    <div class="credit-item-name">
        <strong data-credit-item-name>{{ $item['name'] ?? '' }}</strong>
        @if ($priceEditable)
            <span data-credit-item-price>{{ isset($item) ? ($item['unit_price'] !== null ? 'Catalog price ₱'.$item['unit_price'] : 'No catalog price') : '' }}</span>
        @else
            <span data-credit-item-price>{{ isset($item) ? ($item['unit_price'] !== null ? '₱'.$item['unit_price'].' each' : 'No price set') : '' }}</span>
        @endif
    </div>
    <label class="credit-item-quantity">
        <span>Qty</span>
        <input class="auth-input" type="number" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '1' }}" min="0.001" max="{{ \App\Support\Quantity::MAXIMUM }}" step="0.001" inputmode="decimal" required data-credit-item-quantity>
    </label>
    @if ($priceEditable)
        <label class="credit-item-quantity">
            <span>Price (₱)</span>
            <input class="auth-input" type="number" name="items[{{ $index }}][unit_price]" value="{{ isset($item) ? ($item['entered_unit_price'] ?? $item['unit_price']) : '' }}" min="0" max="9999999999.99" step="0.01" inputmode="decimal" required data-credit-item-unit-price>
        </label>
    @endif
    <span class="credit-item-total" data-credit-item-total aria-live="polite"></span>
    <button class="credit-item-remove" type="button" data-credit-item-remove aria-label="Remove {{ $item['name'] ?? 'product' }}">Remove</button>
</li>
