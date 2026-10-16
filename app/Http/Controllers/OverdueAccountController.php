<?php

namespace App\Http\Controllers;

use App\Services\OverdueAccountService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverdueAccountController extends Controller
{
    public function __invoke(Request $request, OverdueAccountService $overdueAccountService): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:longest,balance'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'longest';

        return view('overdue-accounts.index', [
            'customers' => $overdueAccountService->paginate($request->user()->store, $search, $sort),
            'search' => $search,
            'sort' => $sort,
        ]);
    }
}
