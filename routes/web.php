<?php

use App\Http\Controllers\Admin\AdminDashboardController;
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
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OverdueAccountController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StoreEmailSettingsController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

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

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show')
        ->missing(fn () => response()->view('customers.not-found', status: 404));
});
