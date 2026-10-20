<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.passwords.change');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::min(12)->letters()->numbers(),
            ],
        ], [
            'current_password.required' => 'Please enter your current password.',
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.required' => 'Please enter a new password.',
            'password.confirmed' => 'The new password confirmation does not match.',
            'password.different' => 'Choose a password different from your current one.',
        ]);

        $user = $request->user();
        $wasTemporaryPassword = $user->must_change_password;

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();
        $request->session()->regenerate();

        if ($wasTemporaryPassword) {
            return redirect()->route($user->isSuperAdmin() ? 'admin.dashboard' : 'dashboard')
                ->with('status', 'Your password has been updated.');
        }

        return redirect()->route('password.edit')->with('status', 'Your password has been updated.');
    }
}
