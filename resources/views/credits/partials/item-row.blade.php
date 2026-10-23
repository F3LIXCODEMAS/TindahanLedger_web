{{-- One selected product. Rendered by the server after a validation error and cloned from a <template> by resources/js/app.js. --}}
<li class="credit-item-row" data-credit-item data-product-id="{{ $item['product_id'] ?? '' }}" data-unit-price="{{ $item['unit_price'] ?? '' }}">
    <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] ?? '' }}" data-credit-item-product>
    <div class="credit-item-name">
        <strong data-credit-item-name>{{ $item['name'] ?? '' }}</strong>
        <span data-credit-item-price>{{ isset($item) ? ($item['unit_price'] !== null ? '₱'.$item['unit_price'].' each' : 'No price set') : '' }}</span>
    </div>
    <label class="credit-item-quantity">
        <span>Qty</span>
        <input class="auth-input" type="number" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '1' }}" min="0.001" max="{{ \App\Support\Quantity::MAXIMUM }}" step="0.001" inputmode="decimal" required data-credit-item-quantity>
    </label>
    <span class="credit-item-total" data-credit-item-total aria-live="polite"></span>
    <button class="credit-item-remove" type="button" data-credit-item-remove aria-label="Remove {{ $item['name'] ?? 'product' }}">Remove</button>
</li>
