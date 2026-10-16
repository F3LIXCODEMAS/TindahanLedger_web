<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetTemporaryPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'temporary_password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'temporary_password.required' => 'Please enter a temporary password.',
            'temporary_password.confirmed' => 'The temporary password confirmation does not match.',
        ];
    }
}
