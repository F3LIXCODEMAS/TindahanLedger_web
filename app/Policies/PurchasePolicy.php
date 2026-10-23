<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PurchasePolicy
{
    /**
     * Another store's purchase responds as not found, so owners cannot learn
     * which purchase IDs exist elsewhere.
     */
    public function view(User $user, Purchase $purchase): Response
    {
        $storeId = $user->isStoreOwner() ? $user->store?->getKey() : null;

        return $storeId !== null && $purchase->store_id === $storeId
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
