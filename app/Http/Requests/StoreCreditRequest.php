<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'itemized_list' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:500'],
            'repayment_deadline' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'itemized_list' => $this->normalizeOptionalText($this->input('itemized_list')),
            'note' => $this->normalizeOptionalText($this->input('note')),
            'repayment_deadline' => $this->input('repayment_deadline') ?: null,
            'idempotency_key' => is_string($this->input('idempotency_key'))
                ? Str::lower(trim($this->input('idempotency_key')))
                : $this->input('idempotency_key'),
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
}
