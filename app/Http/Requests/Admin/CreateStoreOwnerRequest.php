<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateStoreOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $username = $this->input('username');
        $email = $this->input('email');

        $this->merge([
            'name' => is_string($name) ? Str::squish($name) : $name,
            'username' => is_string($username) && trim($username) !== '' ? User::normalizeUsername($username) : $username,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:120',
                'regex:/^[\pL\pN][\pL\pN .\'&_-]*$/u',
                Rule::unique('users', 'username'),
            ],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'temporary_password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the owner name.',
            'username.required' => 'Please enter a username.',
            'username.regex' => 'Usernames may contain letters, numbers, spaces, and . \' & _ - characters.',
            'username.unique' => 'This username is already taken.',
            'email.required' => 'Please enter the owner email address.',
            'email.unique' => 'This email address is already used by another account.',
            'temporary_password.required' => 'Please enter a temporary password.',
            'temporary_password.confirmed' => 'The temporary password confirmation does not match.',
        ];
    }

    /**
     * @return array{name: string, username: string, email: string, temporary_password: string}
     */
    public function ownerDetails(): array
    {
        return $this->safe()->only(['name', 'username', 'email', 'temporary_password']);
    }
}
