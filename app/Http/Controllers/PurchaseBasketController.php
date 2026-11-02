<?php

namespace App\Http\Controllers;

use App\Services\PurchaseBasketService;
use Illuminate\Http\Request;

class PurchaseBasketController extends Controller
{
    public function index(
        Request $request,
        PurchaseBasketService $purchaseBasketService
    ) {
        $store = $request->user()->store;

        if (!$store) {
            abort(403, 'You do not have an assigned store.');
        }

        $baskets = $purchaseBasketService->getBaskets($store);

        return response()->json([
            'success' => true,
            'total_baskets' => $baskets->count(),
            'message' => $baskets->isEmpty()
                ? 'Insufficient purchase data for product recommendations.'
                : 'Purchase baskets generated successfully.',
             'baskets' => $baskets,
        ]);
    }
}
