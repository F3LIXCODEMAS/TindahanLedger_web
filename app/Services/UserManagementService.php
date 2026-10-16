<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    /**
     * Create a store-owner account. The role is always assigned here on the
     * server, so the administrative screens can never create another admin.
     *
     * @param  array{name: string, username: string, email: string, temporary_password: string}  $ownerDetails
     */
    public function createStoreOwner(User $actor, array $ownerDetails): User
    {
        return DB::transaction(function () use ($actor, $ownerDetails): User {
            $owner = new User([
                'name' => $ownerDetails['name'],
                'username' => $ownerDetails['username'],
                'email' => $ownerDetails['email'],
                'password' => $ownerDetails['temporary_password'],
            ]);
            $owner->forceFill([
                'role' => User::ROLE_STORE_OWNER,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
            ])->save();

            $this->auditLogger->record($actor, AdminAuditLog::ACTION_USER_CREATED, $owner, [
                'username' => $owner->username,
            ]);

            return $owner;
        });
    }

    /**
     * Deactivate or reactivate a store-owner account. Deactivation only
     * blocks sign-in; customers and financial history are never touched.
     */
    public function changeStatus(User $actor, User $user, string $status): bool
    {
        $this->ensureManageableOwner($actor, $user);

        return DB::transaction(function () use ($actor, $user, $status): bool {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($lockedUser->status === $status) {
                return false;
            }

            $lockedUser->forceFill(['status' => $status])->save();
            $user->setRawAttributes($lockedUser->getAttributes(), true);

            $this->auditLogger->record(
                $actor,
                $status === User::STATUS_INACTIVE
                    ? AdminAuditLog::ACTION_USER_DEACTIVATED
                    : AdminAuditLog::ACTION_USER_REACTIVATED,
                $lockedUser,
            );

            return true;
        });
    }

    /**
     * Issue a temporary password the owner must replace at next sign-in.
     * Remember-me tokens are rotated so saved sign-ins stop working.
     */
    public function setTemporaryPassword(User $actor, User $user, string $temporaryPassword): void
    {
        $this->ensureManageableOwner($actor, $user);

        DB::transaction(function () use ($actor, $user, $temporaryPassword): void {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            $this->auditLogger->record($actor, AdminAuditLog::ACTION_USER_TEMPORARY_PASSWORD_SET, $user);
        });
    }

    private function ensureManageableOwner(User $actor, User $user): void
    {
        if ($actor->is($user) || ! $user->isStoreOwner()) {
            throw ValidationException::withMessages([
                'account' => 'Only store owner accounts can be managed here.',
            ]);
        }
    }
}
