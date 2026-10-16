<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\StoreCustomerController;
use App\Http\Controllers\Admin\StoreOwnerController;
use App\Http\Controllers\Admin\StoreStatusController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CustomerAccessCodeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OverdueAccountController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Portal\CustomerPortalController;
use App\Http\Controllers\Portal\PortalAccessController;
use App\Http\Controllers\StoreEmailSettingsController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

/*
 * Customer self-service portal: QR-only, read-only, and separate from owner
 * and Super Admin routes. It uses the `customer` guard, never `web`.
 */
Route::prefix('portal')->name('portal.')->middleware('private.headers')->group(function () {
    Route::get('/', [PortalAccessController::class, 'entry'])->name('entry');
    Route::post('/access', [PortalAccessController::class, 'store'])
        ->middleware('throttle:customer-portal-access')
        ->name('access');
    Route::post('/exit', [PortalAccessController::class, 'destroy'])->name('exit');
    Route::get('/ledger', [CustomerPortalController::class, 'show'])
        ->middleware('customer.portal')
        ->name('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'password.changed', 'role:'.User::ROLE_SUPER_ADMIN])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::resource('stores', StoreController::class)->except(['destroy']);
        Route::patch('/stores/{store}/status', [StoreStatusController::class, 'update'])->name('stores.status.update');
        Route::patch('/stores/{store}/owner', [StoreOwnerController::class, 'update'])->name('stores.owner.update');
        Route::get('/stores/{store}/customers/{customer}', [StoreCustomerController::class, 'show'])
            ->scopeBindings()
            ->name('stores.customers.show');

        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/analytics', AnalyticsController::class)->name('analytics');

        Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'show']);
        Route::patch('/users/{user}/status', [UserStatusController::class, 'update'])->name('users.status.update');
        Route::put('/users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password.update');
    });

Route::middleware(['auth', 'password.changed', 'role:'.User::ROLE_STORE_OWNER])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/overdue-accounts', OverdueAccountController::class)->name('overdue-accounts.index');

    Route::get('/settings/email-notifications', [StoreEmailSettingsController::class, 'edit'])
        ->name('email-settings.edit');
    Route::put('/settings/email-notifications', [StoreEmailSettingsController::class, 'update'])
        ->name('email-settings.update');
    Route::post('/settings/email-notifications/test', [StoreEmailSettingsController::class, 'test'])
        ->middleware('throttle:email-settings-test')
        ->name('email-settings.test');

    Route::get('/credits/customers/{customer}/summary', [CreditController::class, 'customerSummary'])
        ->name('credits.customer-summary');
    Route::get('/credits/{ledgerEntry}/confirmation', [CreditController::class, 'confirmation'])
        ->name('credits.confirmation');

    Route::get('/payments/customers/{customer}/summary', [PaymentController::class, 'customerSummary'])
        ->name('payments.customer-summary');
    Route::get('/payments/{paymentBatchId}/confirmation', [PaymentController::class, 'confirmation'])
        ->name('payments.confirmation');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');

    Route::middleware('store.active')->group(function () {
        Route::get('/credits/create', [CreditController::class, 'create'])->name('credits.create');
        Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');

        Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');

        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])
            ->name('customers.update');
    });

    Route::middleware('private.headers')->group(function () {
        Route::get('/customers/{customer}/access-code', [CustomerAccessCodeController::class, 'show'])
            ->name('customers.access-code.show');
        Route::get('/customers/{customer}/access-code/download', [CustomerAccessCodeController::class, 'download'])
            ->name('customers.access-code.download');
        Route::get('/customers/{customer}/access-code/card', [CustomerAccessCodeController::class, 'card'])
            ->name('customers.access-code.card');
        Route::post('/customers/{customer}/access-code', [CustomerAccessCodeController::class, 'store'])
            ->middleware('store.active')
            ->name('customers.access-code.store');
        Route::delete('/customers/{customer}/access-code', [CustomerAccessCodeController::class, 'destroy'])
            ->name('customers.access-code.destroy');
    });

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show')
        ->missing(fn () => response()->view('customers.not-found', status: 404));
});
