<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RedeemSukiPointsRequest extends FormRequest
{
    /**
     * Another store's customer is answered as not found by CustomerPolicy.
     */
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && Gate::authorize('update', $customer)->allowed();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.required' => 'Enter the number of points to redeem.',
            'points.integer' => 'Enter a whole number of points.',
            'points.min' => 'Enter at least 1 point to redeem.',
            'points.max' => 'Enter a smaller number of points.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $points = $this->input('points');
        $idempotencyKey = $this->input('idempotency_key');

        $this->merge([
            'points' => is_string($points) ? trim($points) : $points,
            'idempotency_key' => is_string($idempotencyKey) ? Str::lower(trim($idempotencyKey)) : $idempotencyKey,
        ]);
    }
}
