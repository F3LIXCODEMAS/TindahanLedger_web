<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreIsActive
{
    /**
     * Keep a suspended store read-only: its owner may still sign in and view
     * records, but cannot open or submit customer, credit, or payment forms.
     * LedgerService repeats the check inside its locked transaction.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->user()?->store;

        if ($store !== null && ! $store->isActive()) {
            return redirect()->route('dashboard')->withErrors([
                'store' => 'This store is suspended. Records are read-only until an administrator reactivates it.',
            ]);
        }

        return $next($request);
    }
}
