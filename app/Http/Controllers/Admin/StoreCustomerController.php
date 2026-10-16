<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\EmailReminderLog;
use App\Models\Store;
use App\Services\CustomerLedgerService;
use Illuminate\View\View;

class StoreCustomerController extends Controller
{
    /**
     * Read-only view of a store customer's ledger for administrative
     * monitoring. The route uses scoped bindings, so the customer must
     * belong to the store in the URL.
     */
    public function show(Store $store, Customer $customer, CustomerLedgerService $customerLedgerService): View
    {
        return view('admin.stores.customer', [
            'store' => $store,
            'customer' => $customer,
            'ledger' => $customerLedgerService->forCustomer($customer),
            'reminderHistory' => EmailReminderLog::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('scheduled_reminder_date')
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
