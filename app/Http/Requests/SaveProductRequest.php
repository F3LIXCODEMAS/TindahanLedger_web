<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
{
    public const DUPLICATE_NAME_MESSAGE = 'A product with this name already exists in your catalog.';

    public const DUPLICATE_INACTIVE_NAME_MESSAGE = 'An inactive product with this name already exists. Reactivate it instead of adding it again.';

    /**
     * Editing another store's product responds as not found (404).
     */
    public function authorize(): bool
    {
        if ($product = $this->productBeingUpdated()) {
            Gate::authorize('update', $product);
        }

        return $this->ownerStoreId() !== null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? Str::squish($name) : $name,
            'unit_price' => $this->normalizeOptionalText($this->input('unit_price')),
            'description' => $this->normalizeOptionalText($this->input('description')),
        ]);
    }

    private function normalizeOptionalText(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the product name.',
            'name.max' => 'The product name may not exceed 120 characters.',
            'unit_price.numeric' => 'The unit price must be a valid amount.',
            'unit_price.min' => 'The unit price cannot be negative.',
            'unit_price.regex' => 'Enter a price of zero or more with no more than two decimal places.',
            'description.max' => 'The description may not exceed 500 characters.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $duplicate = Product::query()
                ->where('store_id', $this->ownerStoreId())
                ->where('normalized_name', Product::normalizeName((string) $this->input('name')))
                ->when($this->productBeingUpdated(), fn ($query, Product $product) => $query->whereKeyNot($product->getKey()))
                ->first(['id', 'status']);

            if ($duplicate !== null) {
                $validator->errors()->add('name', $duplicate->isActive()
                    ? self::DUPLICATE_NAME_MESSAGE
                    : self::DUPLICATE_INACTIVE_NAME_MESSAGE);
            }
        }];
    }

    private function ownerStoreId(): ?int
    {
        return $this->user()?->store?->getKey();
    }

    private function productBeingUpdated(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
