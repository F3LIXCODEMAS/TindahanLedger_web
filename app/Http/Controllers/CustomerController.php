<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\EmailReminderLog;
use App\Services\CustomerLedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = $request->user()->store->customers()
            ->withSum('ledgerEntries as ledger_credit_total', 'amount')
            ->withSum('payments as customer_payment_total', 'amount')
            ->orderBy('full_name')
            ->paginate(20);

        $customers->getCollection()->transform(function (Customer $customer): Customer {
            $creditTotalInCents = Money::toCents((string) ($customer->ledger_credit_total ?? '0'));
            $paymentTotalInCents = Money::toCents((string) ($customer->customer_payment_total ?? '0'));
            $customer->setAttribute(
                'outstanding_balance',
                Money::fromCents(max(0, $creditTotalInCents - $paymentTotalInCents)),
            );

            return $customer;
        });

        return view('customers.index', ['customers' => $customers]);
    }

    public function create(): View
    {
        return view('customers.create', ['customer' => new Customer]);
    }

    /**
     * Another store's customer gets the same not-found page as a missing one.
     */
    public function show(Customer $customer, CustomerLedgerService $customerLedgerService): View|Response
    {
        if (Gate::denies('view', $customer)) {
            return response()->view('customers.not-found', status: 404);
        }

        return view('customers.show', [
            'customer' => $customer,
            'ledger' => $customerLedgerService->forCustomer($customer),
            'reminderHistory' => EmailReminderLog::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('scheduled_reminder_date')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(SaveCustomerRequest $request): RedirectResponse
    {
        $request->user()->store->customers()->create($request->safe()->only([
            'full_name',
            'contact_number',
            'email',
            'residential_landmark',
            'credit_limit',
        ]));

        return redirect()->route('customers.index')->with('status', 'Customer added successfully.');
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('customers.edit', ['customer' => $customer]);
    }

    public function update(SaveCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->safe()->only([
            'full_name',
            'contact_number',
            'email',
            'residential_landmark',
            'credit_limit',
        ]));

        return redirect()->route('customers.index')->with('status', 'Customer updated successfully.');
    }
}
