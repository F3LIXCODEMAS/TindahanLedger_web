<?php

namespace App\Http\Controllers;

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\InvalidPurchaseItems;
use App\Exceptions\StoreSuspended;
use App\Http\Requests\StoreCreditRequest;
use App\Mail\CreditEntryReceipt;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\User;
use App\Services\LatePenaltyService;
use App\Services\LedgerService;
use App\Services\MailDeliveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CreditController extends Controller
{
    private const PRODUCT_SEARCH_LIMIT = 20;

    public function create(Request $request, LedgerService $ledgerService, LatePenaltyService $latePenaltyService): View
    {
        $penaltyPolicy = $latePenaltyService->activePolicyFor($request->user()->store);
        $customers = $request->user()->store->customers()
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name', 'contact_number', 'credit_limit']);
        $requestedCustomerId = old('customer_id', $request->query('customer_id'));
        $selectedCustomer = is_numeric($requestedCustomerId)
            ? $customers->firstWhere('id', (int) $requestedCustomerId)
            : null;

        return view('credits.create', [
            'customers' => $customers,
            'selectedCustomerId' => $selectedCustomer?->id,
            'selectedSummary' => $selectedCustomer
                ? $ledgerService->financialSummary($selectedCustomer)
                : null,
            'idempotencyKey' => Str::uuid()->toString(),
            'penaltyPolicy' => $penaltyPolicy,
            'selectedItems' => $this->previouslySelectedItems($request),
        ]);
    }

    /**
     * Active products of the owner's store for the Record Credit product
     * picker. Only the fields the form needs are returned.
     */
    public function products(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = Str::squish((string) ($filters['search'] ?? ''));

        $products = $request->user()->store->products()
            ->where('status', Product::STATUS_ACTIVE)
            ->when($search !== '', fn (Builder $query) => $query->whereNameContains($search))
            ->orderBy('normalized_name')
            ->orderBy('id')
            ->limit(self::PRODUCT_SEARCH_LIMIT)
            ->get(['id', 'name', 'unit_price']);

        return response()->json([
            'products' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'unit_price' => $product->unit_price,
            ])->all(),
        ]);
    }

    /**
     * Rebuild the selected product rows after a validation error. Products
     * outside the owner's store are dropped; the submitted quantity is kept
     * as entered so the owner can correct it.
     *
     * @return list<array{product_id: int, name: string, unit_price: string|null, quantity: string}>
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
                ];
            }
        }

        return $selectedItems;
    }

    public function customerSummary(Customer $customer, LedgerService $ledgerService): JsonResponse
    {
        Gate::authorize('view', $customer);

        return response()->json($ledgerService->financialSummary($customer));
    }

    public function store(
        StoreCreditRequest $request,
        LedgerService $ledgerService,
        MailDeliveryService $mailDeliveryService,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $customer = $request->user()->store->customers()->findOrFail($validated['customer_id']);
            $itemizedText = $validated['itemized_list'] ?? null;
            $itemizedList = $itemizedText === null
                ? null
                : [$itemizedText];
            $entry = $ledgerService->recordCredit($customer, [
                'amount' => $validated['amount'],
                'itemized_list' => $itemizedList,
                'note' => $validated['note'] ?? null,
                'repayment_deadline' => $validated['repayment_deadline'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
                'penalty_policy_acknowledged' => $request->boolean('penalty_policy_acknowledged'),
                'items' => array_map(fn (array $item): array => [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (string) $item['quantity'],
                ], array_values($validated['items'] ?? [])),
                'recorded_by_user_id' => $request->user()->getKey(),
            ]);
        } catch (CreditLimitExceeded $exception) {
            return back()->withInput()->withErrors(['amount' => $exception->getMessage()]);
        } catch (InvalidPurchaseItems $exception) {
            return back()->withInput()->withErrors(['items' => $exception->getMessage()]);
        } catch (StoreSuspended $exception) {
            return redirect()->route('dashboard')->withErrors(['store' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'credit' => 'Unable to record the credit. Please try again.',
            ]);
        }

        $owner = $request->user();

        if ($entry->wasRecentlyCreated && $owner instanceof User) {
            $mailDeliveryService->sendAsOwner(
                $owner,
                new CreditEntryReceipt($customer, $entry),
                $customer->email,
                'credit_receipt',
            );
        }

        return redirect()->route('credits.confirmation', $entry)
            ->with('status', 'Credit recorded successfully.');
    }

    public function confirmation(LedgerEntry $ledgerEntry): View
    {
        Gate::authorize('view', $ledgerEntry);
        abort_if($ledgerEntry->isLatePenalty(), 404);

        return view('credits.confirmation', [
            'ledgerEntry' => $ledgerEntry->load('purchase.items'),
            'customer' => $ledgerEntry->customer,
            'newBalance' => $ledgerEntry->running_balance,
        ]);
    }
}
