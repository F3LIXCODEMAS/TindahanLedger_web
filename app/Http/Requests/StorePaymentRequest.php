<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StorePaymentRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');
        $idempotencyKey = $this->input('idempotency_key');

        $this->merge([
            'note' => is_string($note) && trim($note) === '' ? null : $note,
            'idempotency_key' => is_string($idempotencyKey)
                ? Str::lower(trim($idempotencyKey))
                : $idempotencyKey,
        ]);
    }
}
