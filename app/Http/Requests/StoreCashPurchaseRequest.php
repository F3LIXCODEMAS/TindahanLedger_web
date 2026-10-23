<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPurchaseItems;
use App\Services\CashPurchaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCashPurchaseRequest extends FormRequest
{
    use ValidatesPurchaseItems;

    /**
     * Same money format as credit amounts and catalog prices.
     */
    private const MONEY_PATTERN = '/^\d{1,10}(?:\.\d{1,2})?$/D';

    public const PRICE_MESSAGE = 'Enter a price of zero or more with no more than two decimal places.';

    public function authorize(): bool
    {
        return $this->user()?->store !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => is_string($this->input('idempotency_key'))
                ? Str::lower(trim($this->input('idempotency_key')))
                : $this->input('idempotency_key'),
        ]);
    }

    /**
     * A missing unit price falls back to the catalog price in
     * CashPurchaseService, which rejects products that have neither.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('store_id', $this->user()?->store?->getKey()),
            ],
            'items' => ['required', 'array', 'min:1', 'max:'.CashPurchaseService::MAX_ITEMS],
            'items.*' => ['array:product_id,quantity,unit_price'],
            ...$this->purchaseItemRules(),
            'items.*.unit_price' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN, 'numeric', 'min:0'],
            'expected_total' => ['required', 'string', 'regex:'.self::MONEY_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.integer' => 'Choose a customer from your store, or leave it empty for a walk-in sale.',
            'customer_id.exists' => 'Choose a customer from your store, or leave it empty for a walk-in sale.',
            'items.required' => 'Add at least one product to the cash purchase.',
            'items.min' => 'Add at least one product to the cash purchase.',
            'items.max' => 'A cash purchase can list at most '.CashPurchaseService::MAX_ITEMS.' products.',
            ...$this->purchaseItemMessages(),
            'items.*.unit_price.string' => self::PRICE_MESSAGE,
            'items.*.unit_price.regex' => self::PRICE_MESSAGE,
            'items.*.unit_price.numeric' => self::PRICE_MESSAGE,
            'items.*.unit_price.min' => self::PRICE_MESSAGE,
            'expected_total.required' => 'Review the total before recording the cash purchase.',
            'expected_total.regex' => 'Review the total before recording the cash purchase.',
        ];
    }
}
