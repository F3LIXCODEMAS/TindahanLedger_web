<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaiveLatePenaltyRequest;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Services\LatePenaltyService;
use Illuminate\Http\RedirectResponse;

class LatePenaltyWaiverController extends Controller
{
    public function store(WaiveLatePenaltyRequest $request, Customer $customer, LedgerEntry $ledgerEntry, LatePenaltyService $latePenaltyService): RedirectResponse
    {
        $latePenaltyService->waive($ledgerEntry, $request->user(), $request->validated('reason'));

        return redirect()->route('customers.show', $customer)->with('status', 'Late payment fee waived. The waiver is recorded in the ledger.');
    }
}
