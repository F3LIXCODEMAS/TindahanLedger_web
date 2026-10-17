<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLatePenaltyPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStoreOwner() === true && $this->user()->store !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'fee' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:'.(int) config('penalties.max_grace_days')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'disclosure_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fee.gt' => 'The late fee must be greater than zero.',
            'fee.regex' => 'Enter a peso amount with no more than two decimal places.',
            'grace_days.min' => 'The grace period cannot be negative.',
            'grace_days.max' => 'The grace period may not exceed :max days.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('fee')) {
                return;
            }

            $maxFeeCents = (int) config('penalties.max_fee_cents');

            if (Money::toCents((string) $this->input('fee')) > $maxFeeCents) {
                $validator->errors()->add('fee', 'The late fee may not exceed the approved maximum of ₱'.Money::fromCents($maxFeeCents).'.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $disclosure = $this->input('disclosure_text');

        $this->merge([
            'is_enabled' => $this->boolean('is_enabled'),
            'fee' => is_string($this->input('fee')) ? trim($this->input('fee')) : $this->input('fee'),
            'disclosure_text' => is_string($disclosure) && trim($disclosure) !== '' ? trim($disclosure) : null,
        ]);
    }
}
