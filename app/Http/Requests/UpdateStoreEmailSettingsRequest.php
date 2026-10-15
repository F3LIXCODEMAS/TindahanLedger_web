<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateStoreEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $storeName = $this->input('store_name');
        $senderEmail = $this->input('sender_email');
        $appPassword = $this->input('app_password');

        $this->merge([
            'store_name' => is_string($storeName) ? trim($storeName) : $storeName,
            'sender_email' => is_string($senderEmail) ? Str::lower(trim($senderEmail)) : $senderEmail,
            'app_password' => is_string($appPassword)
                ? (preg_replace('/\s+/', '', $appPassword) ?? $appPassword)
                : $appPassword,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hasUsablePassword = $this->user()?->storeEmailSetting?->hasSmtpPassword() ?? false;

        return [
            'store_name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sender_email' => ['required', 'email', 'max:255'],
            'app_password' => [
                Rule::requiredIf(! $hasUsablePassword),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_name.required' => 'Please enter your store name.',
            'store_name.not_regex' => 'Please enter your store name on one line.',
            'sender_email.required' => 'Please enter your Gmail address.',
            'sender_email.email' => 'Please enter a valid email address.',
            'app_password.required' => 'Please enter your Google App Password.',
        ];
    }
}
