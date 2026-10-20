<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\CustomerAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * QR-only entry to the customer portal. The QR code opens the entry page with
 * the token in the URL fragment; the page posts it here once and the browser
 * continues on token-free URLs. No password, passcode, or email check is used:
 * a valid QR code proves possession of the code, not the holder's identity.
 */
class PortalAccessController extends Controller
{
    public const SESSION_CREDENTIAL_KEY = 'customer_portal.credential_id';

    public const SESSION_LAST_ACTIVITY_KEY = 'customer_portal.last_activity_at';

    public function entry(Request $request): View|RedirectResponse
    {
        if (Auth::guard('customer')->check() && $request->session()->has(self::SESSION_CREDENTIAL_KEY)) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.entry');
    }

    public function store(Request $request, CustomerAccessService $customerAccess): RedirectResponse
    {
        $token = $request->input('token');
        $credential = is_string($token) ? $customerAccess->resolve($token) : null;

        if ($credential === null) {
            return redirect()->route('portal.entry')->with(
                'portal_message',
                'This QR code is not valid. It may have been replaced or revoked. Please ask your store for a new one.',
            );
        }

        if (! $credential->customer->store->isActive()) {
            return redirect()->route('portal.entry')->with(
                'portal_message',
                'This store’s customer portal is temporarily unavailable. Please check with the store.',
            );
        }

        $request->session()->regenerate();
        Auth::guard('customer')->login($credential->customer);
        $request->session()->put([
            self::SESSION_CREDENTIAL_KEY => $credential->getKey(),
            self::SESSION_LAST_ACTIVITY_KEY => now()->getTimestamp(),
        ]);
        $credential->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('portal.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.entry')
            ->with('portal_message', 'You have exited the portal. Scan your QR code to view your ledger again.');
    }
}
