<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, UserManagementService $userManagement): RedirectResponse
    {
        $status = $request->validated('status');
        $changed = $userManagement->changeStatus($request->user(), $user, $status);

        $message = match (true) {
            ! $changed => 'The account status was already up to date.',
            $status === User::STATUS_INACTIVE => 'Account deactivated. The owner is signed out and cannot sign in. Store records are unchanged.',
            default => 'Account reactivated. The owner can sign in again.',
        };

        return redirect()->route('admin.users.show', $user)->with('status', $message);
    }
}
