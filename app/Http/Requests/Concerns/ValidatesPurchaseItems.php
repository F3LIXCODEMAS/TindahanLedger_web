<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Validation\Rule;

/**
 * Product and quantity rules shared by every form that records purchase
 * items (Record Credit and Record Cash Purchase), so both accept exactly
 * the same products and quantities. The services check them again inside
 * their transactions.
 */
trait ValidatesPurchaseItems
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function purchaseItemRules(): array
    {
        return [
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')
                    ->where('store_id', $this->user()?->store?->getKey())
                    ->where('status', Product::STATUS_ACTIVE),
            ],
            'items.*.quantity' => ['required', 'string', 'regex:'.Quantity::PATTERN, 'numeric', 'gt:0', 'max:'.Quantity::MAXIMUM],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function purchaseItemMessages(): array
    {
        return [
            'items.*.array' => 'Choose products from your active catalog.',
            'items.*.product_id.required' => 'Choose products from your active catalog.',
            'items.*.product_id.integer' => 'Choose products from your active catalog.',
            'items.*.product_id.exists' => 'One or more selected products are not available in your active catalog. Remove them and try again.',
            'items.*.product_id.distinct' => 'Each product can only be added once.',
            'items.*.quantity.required' => 'Enter a quantity for each selected product.',
            'items.*.quantity.string' => Quantity::FORMAT_MESSAGE,
            'items.*.quantity.regex' => Quantity::FORMAT_MESSAGE,
            'items.*.quantity.numeric' => Quantity::FORMAT_MESSAGE,
            'items.*.quantity.gt' => Quantity::FORMAT_MESSAGE,
            'items.*.quantity.max' => Quantity::MAXIMUM_MESSAGE,
        ];
    }
}
