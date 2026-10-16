<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReassignStoreOwnerRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreManagementService;
use Illuminate\Http\RedirectResponse;

class StoreOwnerController extends Controller
{
    public function update(ReassignStoreOwnerRequest $request, Store $store, StoreManagementService $storeManagement): RedirectResponse
    {
        $storeManagement->reassignOwner(
            $request->user(),
            $store,
            User::query()->findOrFail($request->validated('owner_user_id')),
        );

        return redirect()->route('admin.stores.show', $store)
            ->with('status', 'Store owner reassigned. Existing customers and transactions remain with this store.');
    }
}
