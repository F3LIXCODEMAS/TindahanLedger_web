<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentExceedsBalance;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
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
        $selectedSummary = $selectedCustomer
            ? $ledgerService->financialSummary($selectedCustomer)
            : null;

        return view('payments.create', [
            'customers' => $customers,
            'selectedCustomerId' => $selectedCustomer?->id,
            'selectedSummary' => $selectedSummary,
            'idempotencyKey' => Str::uuid()->toString(),
        ]);
    }

    public function customerSummary(Customer $customer, LedgerService $ledgerService): JsonResponse
    {
        return response()->json($ledgerService->financialSummary($customer));
    }

    public function store(StorePaymentRequest $request, LedgerService $ledgerService): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $customer = Customer::query()->findOrFail($validated['customer_id']);
            $receipt = $ledgerService->recordCustomerPayment($customer, [
                'amount' => $validated['amount'],
                'note' => $validated['note'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
            ]);
        } catch (PaymentExceedsBalance $exception) {
            return back()->withInput()->withErrors(['amount' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'payment' => 'Unable to record the payment. Please try again.',
            ]);
        }

        return redirect()->route('payments.confirmation', $receipt['payment_batch_id'])
            ->with('status', 'Payment recorded successfully.')
            ->with('paymentReceipt', [
                'payment_batch_id' => $receipt['payment_batch_id'],
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'total_amount' => $receipt['total_amount'],
                'previous_balance' => $receipt['previous_balance'],
                'remaining_balance' => $receipt['remaining_balance'],
                'debt_cycle_completed' => $receipt['debt_cycle_completed'],
            ]);
    }

    public function confirmation(string $paymentBatchId, LedgerService $ledgerService): View
    {
        $payments = Payment::query()
            ->with('customer')
            ->where('payment_batch_id', $paymentBatchId)
            ->orderBy('id')
            ->get();

        abort_if($payments->isEmpty(), 404);

        $customer = $payments->first()->customer;
        $receipt = session('paymentReceipt');

        if (! is_array($receipt) || ($receipt['payment_batch_id'] ?? null) !== $paymentBatchId) {
            $paymentAmountInCents = $payments->sum(
                fn (Payment $payment): int => Money::toCents($payment->amount),
            );
            $remainingBalance = $ledgerService->financialSummary($customer)['outstanding_balance'];
            $remainingBalanceInCents = Money::toCents($remainingBalance);

            $receipt = [
                'payment_batch_id' => $paymentBatchId,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'total_amount' => Money::fromCents($paymentAmountInCents),
                'previous_balance' => Money::fromCents($remainingBalanceInCents + $paymentAmountInCents),
                'remaining_balance' => $remainingBalance,
                'debt_cycle_completed' => $remainingBalanceInCents === 0,
            ];
        }

        return view('payments.confirmation', ['receipt' => $receipt]);
    }
}
