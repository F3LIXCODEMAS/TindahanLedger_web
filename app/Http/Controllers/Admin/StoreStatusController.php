<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStoreStatusRequest;
use App\Models\Store;
use App\Services\StoreManagementService;
use Illuminate\Http\RedirectResponse;

class StoreStatusController extends Controller
{
    public function update(UpdateStoreStatusRequest $request, Store $store, StoreManagementService $storeManagement): RedirectResponse
    {
        $status = $request->validated('status');
        $changed = $storeManagement->changeStatus($request->user(), $store, $status);

        $message = match (true) {
            ! $changed => 'The store status was already up to date.',
            $status === Store::STATUS_SUSPENDED => 'Store suspended. Its owner can view records but cannot record credits or payments.',
            default => 'Store reactivated. Its owner can record credits and payments again.',
        };

        return redirect()->route('admin.stores.show', $store)->with('status', $message);
    }
}
