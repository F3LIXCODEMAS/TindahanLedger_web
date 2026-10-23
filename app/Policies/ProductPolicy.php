<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function update(User $user, Product $product): Response
    {
        return $this->belongsToUserStore($user, $product);
    }

    /**
     * Another store's product responds as not found, so owners cannot learn
     * which product IDs exist elsewhere.
     */
    private function belongsToUserStore(User $user, Product $product): Response
    {
        $storeId = $user->isStoreOwner() ? $user->store?->getKey() : null;

        return $storeId !== null && $product->store_id === $storeId
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
