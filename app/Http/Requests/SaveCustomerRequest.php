<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $fullName = $this->input('full_name');
        $contactNumber = $this->input('contact_number');
        $email = $this->input('email');
        $landmark = $this->input('residential_landmark');

        $this->merge([
            'full_name' => is_string($fullName) ? Str::squish($fullName) : $fullName,
            'contact_number' => $this->normalizeContactNumber($contactNumber),
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'residential_landmark' => is_string($landmark) ? Str::squish($landmark) : $landmark,
        ]);
    }

    public function rules(): array
    {
        $emailRule = Rule::unique('customers', 'email');

        if ($customer = $this->customerBeingUpdated()) {
            $emailRule->ignore($customer);
        }

        return [
            'full_name' => ['required', 'string', 'max:120'],
            'contact_number' => ['required', 'string', 'regex:/^\+639\d{9}$/D', 'max:13'],
            'email' => ['required', 'email', 'max:255', $emailRule],
            'residential_landmark' => ['required', 'string', 'max:255'],
            'credit_limit' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Please enter the customer name.',
            'full_name.max' => 'The customer name may not exceed 120 characters.',
            'contact_number.required' => 'Please enter a contact number.',
            'contact_number.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
            'email.required' => 'Please enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email address is already registered to another customer.',
            'residential_landmark.required' => 'Please enter a residential landmark.',
            'residential_landmark.max' => 'The residential landmark may not exceed 255 characters.',
            'credit_limit.required' => 'Please enter a credit limit.',
            'credit_limit.numeric' => 'The credit limit must be a valid amount.',
            'credit_limit.gt' => 'The credit limit must be greater than zero.',
            'credit_limit.regex' => 'Enter a positive amount with no more than two decimal places.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['full_name', 'contact_number', 'credit_limit'])) {
                return;
            }

            $this->validateDuplicateCustomer($validator);
            $this->validateCreditLimit($validator);
        }];
    }

    private function validateDuplicateCustomer(Validator $validator): void
    {
        $fullName = Str::lower(Str::squish((string) $this->input('full_name')));
        $contactNumber = (string) $this->input('contact_number');
        $customer = $this->customerBeingUpdated();
        $contactNumbers = [$contactNumber];

        if (preg_match('/^\+639\d{9}$/D', $contactNumber)) {
            $contactNumbers[] = '0'.substr($contactNumber, 3);
        }

        $duplicateExists = Customer::query()
            ->whereIn('contact_number', $contactNumbers)
            ->when($customer, fn ($query) => $query->where('id', '!=', $customer->getKey()))
            ->get(['id', 'full_name'])
            ->contains(fn (Customer $existingCustomer): bool => Str::lower(Str::squish($existingCustomer->full_name)) === $fullName);

        if ($duplicateExists) {
            $validator->errors()->add('contact_number', 'A customer with this name and contact number already exists.');
        }
    }

    private function validateCreditLimit(Validator $validator): void
    {
        $customer = $this->customerBeingUpdated();

        if (! $customer) {
            return;
        }

        $totalCreditsInCents = Money::toCents((string) $customer->ledgerEntries()->sum('amount'));
        $totalPaymentsInCents = Money::toCents((string) $customer->payments()->sum('amount'));
        $outstandingBalanceInCents = max(0, $totalCreditsInCents - $totalPaymentsInCents);
        $newCreditLimitInCents = Money::toCents((string) $this->input('credit_limit'));

        if ($newCreditLimitInCents < $outstandingBalanceInCents) {
            $validator->errors()->add(
                'credit_limit',
                'The credit limit cannot be lower than the customer’s current outstanding balance.',
            );
        }
    }

    private function customerBeingUpdated(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    private function normalizeContactNumber(mixed $contactNumber): mixed
    {
        if (! is_string($contactNumber)) {
            return $contactNumber;
        }

        $trimmedContactNumber = trim($contactNumber);
        $digits = preg_replace('/\D+/', '', $trimmedContactNumber) ?? '';

        if (preg_match('/^09\d{9}$/D', $digits)) {
            return '+63'.substr($digits, 1);
        }

        if (preg_match('/^639\d{9}$/D', $digits)) {
            return '+'.$digits;
        }

        return $trimmedContactNumber;
    }
}
