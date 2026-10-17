<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateStoreRequest;
use App\Http\Requests\Admin\UpdateStoreRequest;
use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\PlatformMetricsService;
use App\Services\StoreManagementService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreController extends Controller
{
    /**
     * Allowed sort keys mapped to their columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'name' => 'stores.store_name',
        'registered' => 'stores.created_at',
        'customers' => 'customer_count',
        'outstanding' => 'outstanding_balance',
    ];

    public function index(Request $request, PlatformMetricsService $platformMetrics): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([Store::STATUS_ACTIVE, Store::STATUS_SUSPENDED])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'registered';
        $direction = $filters['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $stores = $platformMetrics->storesWithBalances()
            ->with('owner:id,name,username,email')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('stores.store_name', 'like', '%'.$search.'%')
                    ->orWhereHas('owner', fn (Builder $owner) => $owner
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('username', 'like', '%'.$search.'%'));
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('stores.status', $status))
            ->orderBy(self::SORT_COLUMNS[$sort], $direction)
            ->orderBy('stores.id', $direction)
            ->paginate(20)
            ->withQueryString();

        $stores->getCollection()->each(fn (Store $store) => $this->formatBalances($store));

        return view('admin.stores.index', [
            'stores' => $stores,
            'search' => $search,
            'status' => $filters['status'] ?? '',
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    public function create(): View
    {
        return view('admin.stores.create');
    }

    public function store(CreateStoreRequest $request, StoreManagementService $storeManagement): RedirectResponse
    {
        $store = $storeManagement->createStoreWithOwner(
            $request->user(),
            $request->validated('store_name'),
            $request->ownerDetails(),
        );

        return redirect()->route('admin.stores.show', $store)
            ->with('status', 'Store created. Share the username and temporary password with the owner securely.');
    }

    public function show(Request $request, Store $store, PlatformMetricsService $platformMetrics): View
    {
        $storeWithBalances = $platformMetrics->storesWithBalances()
            ->with('owner')
            ->whereKey($store->getKey())
            ->firstOrFail();
        $this->formatBalances($storeWithBalances);

        $customers = $store->customers()
            ->withSum('ledgerEntries as ledger_credit_total', 'amount')
            ->withSum('payments as customer_payment_total', 'amount')
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(20, pageName: 'customers_page')
            ->withQueryString();

        $customers->getCollection()->each(function (Customer $customer): void {
            $customer->setAttribute('outstanding_balance', Money::fromCents(max(
                0,
                Money::sumToCents($customer->ledger_credit_total) - Money::sumToCents($customer->customer_payment_total),
            )));
        });

        return view('admin.stores.show', [
            'store' => $storeWithBalances,
            'customers' => $customers,
            'recentActivity' => $this->recentActivity($store),
            'auditLogs' => AdminAuditLog::query()
                ->forSubject(AdminAuditLog::SUBJECT_STORE, $store->getKey())
                ->with('actor:id,name')
                ->latest('created_at')
                ->latest('id')
                ->limit(20)
                ->get(),
            'availableOwners' => User::query()
                ->where('role', User::ROLE_STORE_OWNER)
                ->where('status', User::STATUS_ACTIVE)
                ->whereDoesntHave('store')
                ->orderBy('name')
                ->get(['id', 'name', 'username']),
        ]);
    }

    public function edit(Store $store): View
    {
        return view('admin.stores.edit', ['store' => $store]);
    }

    public function update(UpdateStoreRequest $request, Store $store, StoreManagementService $storeManagement): RedirectResponse
    {
        $storeManagement->updateDetails($request->user(), $store, $request->validated('store_name'));

        return redirect()->route('admin.stores.show', $store)->with('status', 'Store details updated.');
    }

    private function formatBalances(Store $store): void
    {
        $store->setAttribute('outstanding_balance', Money::fromCents(Money::sumToCents($store->outstanding_balance)));
        $store->setAttribute('overdue_balance', Money::fromCents(Money::sumToCents($store->overdue_balance)));
        $store->setAttribute('customer_count', (int) $store->customer_count);
    }

    /**
     * The store's most recent credits and payments, newest first.
     *
     * @return Collection<int, array{type: string, customer_name: string, amount: string, occurred_at: Carbon}>
     */
    private function recentActivity(Store $store): Collection
    {
        $credits = LedgerEntry::query()
            ->with('customer:id,full_name')
            ->whereRelation('customer', 'store_id', $store->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (LedgerEntry $entry): array => [
                'type' => $entry->isLatePenalty() ? 'Late payment fee' : 'Credit',
                'customer_name' => $entry->customer->full_name,
                'amount' => $entry->amount,
                'occurred_at' => $entry->created_at,
            ]);

        $payments = Payment::query()
            ->with('customer:id,full_name')
            ->whereRelation('customer', 'store_id', $store->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->groupBy(fn (Payment $payment): string => $payment->payment_batch_id ?? 'payment-'.$payment->id)
            ->map(fn (Collection $allocations): array => [
                'type' => match ($allocations->first()->kind) {
                    Payment::KIND_LOYALTY_REDEMPTION => 'Suki Points redeemed',
                    Payment::KIND_PENALTY_WAIVER => 'Late fee waived',
                    default => 'Payment',
                },
                'customer_name' => $allocations->first()->customer->full_name,
                'amount' => Money::fromCents($allocations->sum(fn (Payment $payment): int => Money::toCents($payment->amount))),
                'occurred_at' => $allocations->max('created_at'),
            ])
            ->values();

        return $credits->concat($payments)
            ->sortByDesc(fn (array $activity): int => $activity['occurred_at']->getTimestamp())
            ->take(10)
            ->values();
    }
}
