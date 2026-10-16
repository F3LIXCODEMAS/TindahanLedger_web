<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Owner-side management of a customer's portal QR code. Every action is
 * authorized against the owner's store; another store's customer is 404.
 */
class CustomerAccessCodeController extends Controller
{
    public function show(Customer $customer, CustomerAccessService $customerAccess): View
    {
        Gate::authorize('view', $customer);

        $credential = $customer->activeAccessCredential;

        return view('customers.access-code', [
            'customer' => $customer,
            'credential' => $credential,
            'qrSvg' => $credential ? $customerAccess->qrSvg($credential) : null,
        ]);
    }

    public function download(Customer $customer, CustomerAccessService $customerAccess): Response
    {
        Gate::authorize('view', $customer);

        $credential = $customer->activeAccessCredential;
        $qrSvg = $credential ? $customerAccess->qrSvg($credential, 600) : null;

        abort_if($qrSvg === null, 404);

        return response($qrSvg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="tindahanledger-qr-'.Str::slug($customer->full_name).'.svg"',
        ]);
    }

    public function card(Customer $customer, CustomerAccessService $customerAccess): View
    {
        Gate::authorize('view', $customer);

        $credential = $customer->activeAccessCredential;
        $qrSvg = $credential ? $customerAccess->qrSvg($credential, 260) : null;

        abort_if($qrSvg === null, 404);

        return view('customers.access-card', [
            'customer' => $customer->load('store'),
            'qrSvg' => $qrSvg,
        ]);
    }

    /**
     * Issue a QR code, or replace the current one. The previous code and any
     * portal sessions opened with it stop working immediately.
     */
    public function store(Request $request, Customer $customer, CustomerAccessService $customerAccess): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $wasReplaced = $customer->activeAccessCredential !== null;
        $customerAccess->issue($customer, $request->user());

        return redirect()->route('customers.access-code.show', $customer)->with('status', $wasReplaced
            ? 'A new QR code was created. The previous code no longer works; give the customer the new card.'
            : 'QR code created. Print or download it for the customer.');
    }

    /**
     * Revoke the current QR code without issuing a new one. Allowed even for
     * a suspended store so an exposed code can always be shut off.
     */
    public function destroy(Request $request, Customer $customer, CustomerAccessService $customerAccess): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $customerAccess->revoke($customer, $request->user());

        return redirect()->route('customers.access-code.show', $customer)
            ->with('status', 'QR code revoked. It can no longer open the customer portal.');
    }
}
