<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Route names that stay reachable while a temporary password is in use.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = ['password.edit', 'password.update', 'logout'];

    /**
     * Require users with an administrator-issued temporary password to choose
     * their own password before using the application.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return redirect()->route('password.edit')
                ->with('status', 'Please choose a new password before continuing.');
        }

        return $next($request);
    }
}
