<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\CustomerLedgerService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Read-only ledger for the signed-in portal customer. Every figure comes from
 * CustomerLedgerService, the same source as the owner's customer ledger.
 */
class CustomerPortalController extends Controller
{
    public function show(Request $request, CustomerLedgerService $customerLedgerService): View
    {
        $customer = Auth::guard('customer')->user()->load('store');
        $ledger = $customerLedgerService->forCustomer($customer);
        $perPage = (int) config('customer_portal.history_per_page');
        $newestFirst = $ledger['transactions']->reverse()->values();
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        $transactions = new LengthAwarePaginator(
            $newestFirst->forPage($currentPage, $perPage)->values(),
            $newestFirst->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'pageName' => 'page'],
        );

        return view('portal.dashboard', [
            'customer' => $customer,
            'ledger' => $ledger,
            'transactions' => $transactions,
            'outstandingDeadlines' => $ledger['transactions']
                ->filter(fn (array $transaction): bool => $transaction['type'] === 'credit'
                    && $transaction['deadline'] !== null
                    && $transaction['remaining_amount'] !== '0.00')
                ->sortBy(fn (array $transaction): string => $transaction['deadline']->toDateString())
                ->values(),
        ]);
    }
}
