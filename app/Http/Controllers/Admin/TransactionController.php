<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\TransactionMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionController extends Controller
{
    /**
     * Read-only monitoring of credits and payments across all stores.
     */
    public function index(Request $request, TransactionMonitoringService $transactionMonitoring): View
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(TransactionMonitoringService::TYPES)],
            'customer' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(array_keys(TransactionMonitoringService::SORT_COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        $filters = [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'store_id' => $validated['store_id'] ?? null,
            'type' => $validated['type'] ?? null,
            'customer' => trim((string) ($validated['customer'] ?? '')) ?: null,
        ];
        $sort = $validated['sort'] ?? 'date';
        $direction = $validated['direction'] ?? ($sort === 'store' ? 'asc' : 'desc');

        return view('admin.transactions.index', [
            'transactions' => $transactionMonitoring->paginate($filters, $sort, $direction),
            'totals' => $transactionMonitoring->totals($filters),
            'stores' => Store::query()->orderBy('store_name')->get(['id', 'store_name']),
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }
}
