<?php

namespace App\Http\Requests\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ReassignStoreOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * The new owner must be an active store-owner account without a store.
     * StoreManagementService repeats this check while holding row locks.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', self::unassignedOwnerRule()],
        ];
    }

    /**
     * An active store-owner account that does not own a store yet. Shared
     * with store creation for an existing owner.
     */
    public static function unassignedOwnerRule(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('role', User::ROLE_STORE_OWNER)
            ->where('status', User::STATUS_ACTIVE)
            ->where(fn (Builder $query) => $query->whereNotIn('id', Store::query()->select('owner_user_id')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_user_id.required' => 'Choose the new owner account.',
            'owner_user_id.exists' => 'Choose an active store owner account that is not assigned to another store.',
        ];
    }
}
