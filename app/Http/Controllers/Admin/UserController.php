<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateStoreOwnerRequest;
use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::in([User::ROLE_SUPER_ADMIN, User::ROLE_STORE_OWNER])],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $users = User::query()
            ->with('store:id,owner_user_id,store_name')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($filters['store_id'] ?? null, fn (Builder $query, int|string $storeId) => $query
                ->whereRelation('store', 'id', (int) $storeId))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'stores' => Store::query()->orderBy('store_name')->get(['id', 'store_name']),
            'search' => $search,
            'storeId' => (int) ($filters['store_id'] ?? 0),
            'role' => $filters['role'] ?? '',
            'status' => $filters['status'] ?? '',
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(CreateStoreOwnerRequest $request, UserManagementService $userManagement): RedirectResponse
    {
        $owner = $userManagement->createStoreOwner($request->user(), $request->ownerDetails());

        return redirect()->route('admin.users.show', $owner)
            ->with('status', 'Owner account created. Create a store for this owner, or assign them to an existing store from its details page.');
    }

    public function show(Request $request, User $user): View
    {
        return view('admin.users.show', [
            'account' => $user->load('store'),
            'isOwnAccount' => $request->user()->is($user),
            'auditLogs' => AdminAuditLog::query()
                ->forSubject(AdminAuditLog::SUBJECT_USER, $user->getKey())
                ->with('actor:id,name')
                ->latest('created_at')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }
}
