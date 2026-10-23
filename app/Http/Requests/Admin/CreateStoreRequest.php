<?php

namespace App\Http\Requests\Admin;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates a store with either a new owner account or an existing active
 * owner account that has no store yet. For a new owner, the username
 * defaults to the store name when left blank.
 */
class CreateStoreRequest extends CreateStoreOwnerRequest
{
    public const OWNER_TYPE_NEW = 'new';

    public const OWNER_TYPE_EXISTING = 'existing';

    protected function prepareForValidation(): void
    {
        $storeName = $this->input('store_name');
        $storeName = is_string($storeName) ? Str::squish($storeName) : $storeName;

        $this->merge([
            'store_name' => $storeName,
            'owner_type' => $this->input('owner_type', self::OWNER_TYPE_NEW),
        ]);

        if ($this->usesExistingOwner()) {
            return;
        }

        $username = $this->input('username');
        $this->merge([
            'username' => is_string($username) && trim($username) !== '' ? $username : $storeName,
        ]);

        parent::prepareForValidation();
    }

    /**
     * New-owner fields are only validated, and only returned by
     * `validated()`, when a new owner account is being created.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $storeRules = [
            'store_name' => ['required', 'string', 'max:120'],
            'owner_type' => ['required', Rule::in([self::OWNER_TYPE_NEW, self::OWNER_TYPE_EXISTING])],
        ];

        if ($this->usesExistingOwner()) {
            return [
                ...$storeRules,
                'owner_user_id' => ['required', 'integer', ReassignStoreOwnerRequest::unassignedOwnerRule()],
            ];
        }

        return [...$storeRules, ...parent::rules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_name.required' => 'Please enter the store name.',
            'owner_type.in' => 'Choose whether to create a new owner or use an existing one.',
            'owner_user_id.required' => 'Choose the owner account for this store.',
            'owner_user_id.exists' => 'Choose an active store owner account that is not assigned to another store.',
            ...parent::messages(),
        ];
    }

    public function usesExistingOwner(): bool
    {
        return $this->input('owner_type') === self::OWNER_TYPE_EXISTING;
    }
}
