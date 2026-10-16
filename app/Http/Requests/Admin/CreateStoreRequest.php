<?php

namespace App\Http\Requests\Admin;

use Illuminate\Support\Str;

/**
 * Creates a store and its owner account together. The owner's username
 * defaults to the store name when left blank.
 */
class CreateStoreRequest extends CreateStoreOwnerRequest
{
    protected function prepareForValidation(): void
    {
        $storeName = $this->input('store_name');
        $storeName = is_string($storeName) ? Str::squish($storeName) : $storeName;
        $username = $this->input('username');

        $this->merge([
            'store_name' => $storeName,
            'username' => is_string($username) && trim($username) !== '' ? $username : $storeName,
        ]);

        parent::prepareForValidation();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:120'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_name.required' => 'Please enter the store name.',
            ...parent::messages(),
        ];
    }
}
