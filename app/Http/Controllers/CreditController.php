<?php

namespace App\Http\Controllers;

use App\Exceptions\CreditLimitExceeded;
use App\Http\Requests\StoreCreditRequest;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CreditController extends Controller
{
    public function create(Request $request, LedgerService $ledgerService): View
    {
        $customers = Customer::query()
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name', 'contact_number', 'credit_limit']);
        $requestedCustomerId = old('customer_id', $request->query('customer_id'));
        $selectedCustomer = is_numeric($requestedCustomerId)
            ? $customers->firstWhere('id', (int) $requestedCustomerId)
            : null;

        return view('credits.create', [
            'customers' => $customers,
            'selectedCustomerId' => $selectedCustomer?->id,
            'selectedSummary' => $selectedCustomer
                ? $ledgerService->financialSummary($selectedCustomer)
                : null,
            'idempotencyKey' => Str::uuid()->toString(),
        ]);
    }

    public function customerSummary(Customer $customer, LedgerService $ledgerService): JsonResponse
    {
        return response()->json($ledgerService->financialSummary($customer));
    }

    public function store(StoreCreditRequest $request, LedgerService $ledgerService): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $customer = Customer::query()->findOrFail($validated['customer_id']);
            $itemizedText = $validated['itemized_list'] ?? null;
            $itemizedList = $itemizedText === null
                ? null
                : [$itemizedText];
            $entry = $ledgerService->recordCredit($customer, [
                'amount' => $validated['amount'],
                'itemized_list' => $itemizedList,
                'note' => $validated['note'] ?? null,
                'repayment_deadline' => $validated['repayment_deadline'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
            ]);
        } catch (CreditLimitExceeded $exception) {
            return back()->withInput()->withErrors(['amount' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'credit' => 'Unable to record the credit. Please try again.',
            ]);
        }

        return redirect()->route('credits.confirmation', $entry)
            ->with('status', 'Credit recorded successfully.');
    }

    public function confirmation(LedgerEntry $ledgerEntry): View
    {
        return view('credits.confirmation', [
            'ledgerEntry' => $ledgerEntry,
            'customer' => $ledgerEntry->customer,
            'newBalance' => $ledgerEntry->running_balance,
        ]);
    }
}
