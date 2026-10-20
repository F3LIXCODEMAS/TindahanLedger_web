<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetTemporaryPasswordRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;

class UserPasswordController extends Controller
{
    public function update(SetTemporaryPasswordRequest $request, User $user, UserManagementService $userManagement): RedirectResponse
    {
        $userManagement->setTemporaryPassword($request->user(), $user, $request->validated('temporary_password'));

        return redirect()->route('admin.users.show', $user)
            ->with('status', 'Temporary password issued. The owner must choose a new password at next sign-in.');
    }
}
