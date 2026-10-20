<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Portal\PortalAccessController;
use App\Models\Customer;
use App\Models\CustomerAccessCredential;
use App\Models\Store;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerPortalSession
{
    /**
     * Revalidate a customer portal session on every request: the customer
     * must be signed in through the customer guard, the QR credential that
     * opened the session must still be active and belong to that customer,
     * the store must be active, and the session must not be idle longer than
     * the configured timeout. The customer is always taken from the server
     * session, never from request input.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();
        $credentialId = $request->session()->get(PortalAccessController::SESSION_CREDENTIAL_KEY);
        $lastActivityAt = (int) $request->session()->get(PortalAccessController::SESSION_LAST_ACTIVITY_KEY, 0);

        if (! $customer instanceof Customer || $credentialId === null) {
            return $this->endSession($request, 'Scan your QR code to view your ledger.');
        }

        $idleMinutes = max(1, (int) config('customer_portal.idle_minutes'));

        if (now()->getTimestamp() - $lastActivityAt > $idleMinutes * 60) {
            return $this->endSession(
                $request,
                "Your session ended after {$idleMinutes} minutes of inactivity. Scan your QR code to continue.",
            );
        }

        $credentialIsActive = CustomerAccessCredential::query()
            ->whereKey($credentialId)
            ->where('customer_id', $customer->getKey())
            ->where('status', CustomerAccessCredential::STATUS_ACTIVE)
            ->exists();

        if (! $credentialIsActive) {
            return $this->endSession(
                $request,
                'This QR code is no longer valid. It may have been replaced or revoked. Please ask your store for a new one.',
            );
        }

        $storeStatus = Store::query()->whereKey($customer->store_id)->value('status');

        if ($storeStatus !== Store::STATUS_ACTIVE) {
            return $this->endSession(
                $request,
                'This store’s customer portal is temporarily unavailable. Please check with the store.',
            );
        }

        $request->session()->put(PortalAccessController::SESSION_LAST_ACTIVITY_KEY, now()->getTimestamp());

        return $next($request);
    }

    private function endSession(Request $request, string $message): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.entry')->with('portal_message', $message);
    }
}
