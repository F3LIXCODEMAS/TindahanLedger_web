<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => 'Please enter your username.',
            'username.max' => 'The username may not exceed 120 characters.',
            'password.required' => 'Please enter your password.',
        ]);

        $credentials = [
            'username' => User::normalizeUsername($validated['username']),
            'password' => $validated['password'],
        ];
        $throttleKey = Str::transliterate($credentials['username'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $secondsUntilRetry = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('username'))
                ->withErrors([
                    'username' => "Too many login attempts. Please wait {$secondsUntilRetry} seconds and try again.",
                ]);
        }

        $accountIsInactive = false;
        $authenticated = Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$accountIsInactive): bool {
                $accountIsInactive = ! $user->isActive();

                return ! $accountIsInactive;
            },
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('username'))
                ->withErrors([
                    'username' => $accountIsInactive
                        ? 'This account has been deactivated. Please contact the administrator.'
                        : 'The username or password you entered is incorrect.',
                ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->must_change_password) {
            return redirect()->route('password.edit')
                ->with('status', 'Please choose a new password before continuing.');
        }

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
