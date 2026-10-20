<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only when the authenticated user holds the given
     * role. Store owners must also be assigned to a store, because every
     * owner-facing operation is scoped to that store.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role === $role, 403);

        if ($role === User::ROLE_STORE_OWNER) {
            abort_if($user->store === null, 403, 'Your account is not assigned to a store.');
        }

        return $next($request);
    }
}
