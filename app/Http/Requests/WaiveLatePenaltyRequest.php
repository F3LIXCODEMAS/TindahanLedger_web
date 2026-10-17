<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WaiveLatePenaltyRequest extends FormRequest
{
    /**
     * The penalty must belong to the customer in the URL, and the customer to
     * the owner's store; anything else is answered as not found.
     */
    public function authorize(): bool
    {
        $customer = $this->route('customer');
        $penalty = $this->route('ledgerEntry');

        if (! $customer instanceof Customer || ! $penalty instanceof LedgerEntry) {
            return false;
        }

        Gate::authorize('update', $customer);
        abort_unless($penalty->customer_id === $customer->getKey() && $penalty->isLatePenalty(), 404);

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Enter a reason for waiving the penalty.',
            'reason.min' => 'Enter a reason for waiving the penalty.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        $this->merge(['reason' => is_string($reason) ? trim(preg_replace('/\s+/', ' ', $reason)) : $reason]);
    }
}
