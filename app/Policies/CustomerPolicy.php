<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    /**
     * Store owners may view only their own store's customers. Other stores'
     * customers are reported as missing so their existence is not revealed.
     */
    public function view(User $user, Customer $customer): Response
    {
        return $this->belongsToUserStore($user, $customer);
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->belongsToUserStore($user, $customer);
    }

    private function belongsToUserStore(User $user, Customer $customer): Response
    {
        $storeId = $user->isStoreOwner() ? $user->store?->getKey() : null;

        return $storeId !== null && $customer->store_id === $storeId
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
