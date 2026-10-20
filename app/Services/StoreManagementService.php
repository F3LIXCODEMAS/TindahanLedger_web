<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreManagementService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly UserManagementService $userManagementService,
    ) {}

    /**
     * Create a store together with its new owner account. The owner signs in
     * with the administrator-issued temporary password and must replace it.
     *
     * @param  array{name: string, username: string, email: string, temporary_password: string}  $ownerDetails
     */
    public function createStoreWithOwner(User $actor, string $storeName, array $ownerDetails): Store
    {
        return DB::transaction(function () use ($actor, $storeName, $ownerDetails): Store {
            $owner = $this->userManagementService->createStoreOwner($actor, $ownerDetails);

            $store = new Store(['store_name' => $storeName]);
            $store->forceFill([
                'owner_user_id' => $owner->getKey(),
                'status' => Store::STATUS_ACTIVE,
            ])->save();

            $this->auditLogger->record($actor, AdminAuditLog::ACTION_STORE_CREATED, $store, [
                'store_name' => $store->store_name,
                'owner_user_id' => $owner->getKey(),
            ]);

            return $store;
        });
    }

    public function updateDetails(User $actor, Store $store, string $storeName): void
    {
        DB::transaction(function () use ($actor, $store, $storeName): void {
            $previousName = $store->store_name;
            $store->update(['store_name' => $storeName]);

            if ($previousName !== $store->store_name) {
                $this->auditLogger->record($actor, AdminAuditLog::ACTION_STORE_UPDATED, $store, [
                    'previous_store_name' => $previousName,
                    'store_name' => $store->store_name,
                ]);
            }
        });
    }

    /**
     * Suspend or reactivate a store. The store row is locked so the change
     * waits for in-flight credits and payments, which hold a shared lock.
     */
    public function changeStatus(User $actor, Store $store, string $status): bool
    {
        return DB::transaction(function () use ($actor, $store, $status): bool {
            $lockedStore = Store::query()->lockForUpdate()->findOrFail($store->getKey());

            if ($lockedStore->status === $status) {
                return false;
            }

            $lockedStore->forceFill(['status' => $status])->save();
            $store->setRawAttributes($lockedStore->getAttributes(), true);

            $this->auditLogger->record(
                $actor,
                $status === Store::STATUS_SUSPENDED
                    ? AdminAuditLog::ACTION_STORE_SUSPENDED
                    : AdminAuditLog::ACTION_STORE_REACTIVATED,
                $lockedStore,
            );

            return true;
        });
    }

    /**
     * Hand a store to another owner account. Customers and their credit and
     * payment history stay with the store, so past activity remains
     * attributed to the store where it occurred.
     */
    public function reassignOwner(User $actor, Store $store, User $newOwner): void
    {
        DB::transaction(function () use ($actor, $store, $newOwner): void {
            $lockedStore = Store::query()->lockForUpdate()->findOrFail($store->getKey());
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($newOwner->getKey());
            $ownsAnotherStore = Store::query()->where('owner_user_id', $lockedOwner->getKey())->exists();

            if (! $lockedOwner->isStoreOwner() || ! $lockedOwner->isActive() || $ownsAnotherStore) {
                throw ValidationException::withMessages([
                    'owner_user_id' => 'Choose an active store owner account that is not assigned to another store.',
                ]);
            }

            $previousOwnerId = $lockedStore->owner_user_id;
            $lockedStore->forceFill(['owner_user_id' => $lockedOwner->getKey()])->save();
            $store->setRawAttributes($lockedStore->getAttributes(), true);
            $store->unsetRelation('owner');

            $this->auditLogger->record($actor, AdminAuditLog::ACTION_STORE_OWNER_REASSIGNED, $lockedStore, [
                'previous_owner_user_id' => $previousOwnerId,
                'owner_user_id' => $lockedOwner->getKey(),
            ]);
        });
    }
}
