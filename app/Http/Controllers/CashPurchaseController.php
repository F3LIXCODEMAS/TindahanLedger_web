<?php

namespace App\Http\Controllers;

use App\Exceptions\CashPurchaseRejected;
use App\Exceptions\StoreSuspended;
use App\Http\Requests\StoreCashPurchaseRequest;
use App\Models\Purchase;
use App\Services\CashPurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Record Cash Purchase (DUMS-37): a sale paid on the spot. Kept apart from
 * Record Credit and Record Payment; it never changes what anyone owes.
 */
class CashPurchaseController extends Controller
{
    public function create(Request $request): View
    {
        $store = $request->user()->store;

        return view('cash-purchases.create', [
            'customers' => $store->customers()->orderBy('full_name')->orderBy('id')->get(['id', 'full_name', 'contact_number']),
            'idempotencyKey' => Str::uuid()->toString(),
            'selectedItems' => $this->previouslySelectedItems($request),
        ]);
    }

    public function store(StoreCashPurchaseRequest $request, CashPurchaseService $cashPurchaseService): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $purchase = $cashPurchaseService->record($request->user()->store, $request->user(), [
                'idempotency_key' => $validated['idempotency_key'],
                'customer_id' => $validated['customer_id'] ?? null,
                'items' => array_map(fn (array $item): array => [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? null,
                ], array_values($validated['items'])),
                'expected_total' => $validated['expected_total'],
            ]);
        } catch (CashPurchaseRejected $exception) {
            return back()->withInput()->withErrors([$exception->field => $exception->getMessage()]);
        } catch (StoreSuspended $exception) {
            return redirect()->route('dashboard')->withErrors(['store' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'items' => 'Unable to record the cash purchase. Please try again.',
            ]);
        }

        return redirect()->route('cash-purchases.show', $purchase)
            ->with('status', 'Cash purchase recorded.');
    }

    public function show(Purchase $purchase): View
    {
        Gate::authorize('view', $purchase);
        abort_unless($purchase->isCash(), 404);

        return view('cash-purchases.show', [
            'purchase' => $purchase->load(['items', 'customer:id,full_name', 'recordedBy:id,name']),
        ]);
    }

    /**
     * Rebuild the selected product rows after a validation error, keeping
     * the submitted quantity and price as entered. Products outside the
     * owner's store are dropped.
     *
     * @return list<array{product_id: int, name: string, unit_price: string|null, quantity: string, entered_unit_price: string}>
     */
    private function previouslySelectedItems(Request $request): array
    {
        $submittedItems = array_values(array_filter(
            (array) old('items', []),
            fn (mixed $item): bool => is_array($item) && is_numeric($item['product_id'] ?? null),
        ));

        if ($submittedItems === []) {
            return [];
        }

        $products = $request->user()->store->products()
            ->whereKey(array_map(fn (array $item): int => (int) $item['product_id'], $submittedItems))
            ->get(['id', 'name', 'unit_price'])
            ->keyBy('id');
        $selectedItems = [];

        foreach ($submittedItems as $item) {
            $product = $products->get((int) $item['product_id']);

            if ($product !== null) {
                $selectedItems[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit_price' => $product->unit_price,
                    'quantity' => is_scalar($item['quantity'] ?? null) ? (string) $item['quantity'] : '',
                    'entered_unit_price' => is_scalar($item['unit_price'] ?? null) ? (string) $item['unit_price'] : '',
                ];
            }
        }

        return $selectedItems;
    }
}
