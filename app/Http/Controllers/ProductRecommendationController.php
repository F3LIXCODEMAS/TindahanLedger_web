<?php

namespace App\Http\Controllers;

use App\Models\AssociationRule;
use App\Services\ProductRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only "often bought together" suggestions for the shared Record
 * Credit / Record Cash Purchase product picker (DUMS-40).
 *
 * The store always comes from the signed-in owner; any store ID, metric,
 * or ranking value sent by the browser is ignored. Only product IDs are
 * read, and IDs that are not active products of the owner's store are
 * ignored. Suggestions come from the store's active rule set only; nothing
 * is trained, activated, or written. Recording a transaction still goes
 * through the normal form validation and services.
 *
 * Response `status`: `available`, `no_matches`, `no_active_model` (the
 * store has no published model, for example because it has too few
 * recorded sales), or `empty_basket`.
 */
class ProductRecommendationController extends Controller
{
    public function __invoke(Request $request, ProductRecommendationService $recommendationService): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['nullable', 'array', 'max:'.ProductRecommendationService::MAX_BASKET_PRODUCTS],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $store = $request->user()->store;
        $productIds = array_map('intval', $validated['product_ids'] ?? []);

        if ($productIds === []) {
            return $this->respond('empty_basket');
        }

        if (! $recommendationService->hasActiveModel($store)) {
            return $this->respond('no_active_model');
        }

        $rules = $recommendationService->forBasket($store, $productIds);

        return $this->respond($rules->isEmpty() ? 'no_matches' : 'available', $rules->map(fn (AssociationRule $rule): array => [
            'id' => $rule->consequentProduct->id,
            'name' => $rule->consequentProduct->name,
            'unit_price' => $rule->consequentProduct->unit_price,
            'explanation' => "Frequently bought with {$rule->antecedentProduct->name}.",
        ])->all());
    }

    /**
     * @param  list<array{id: int, name: string, unit_price: string|null, explanation: string}>  $recommendations
     */
    private function respond(string $status, array $recommendations = []): JsonResponse
    {
        return response()->json(['status' => $status, 'recommendations' => $recommendations]);
    }
}
