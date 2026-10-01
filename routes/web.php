<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/credits/create', [CreditController::class, 'create'])->name('credits.create');
    Route::get('/credits/customers/{customer}/summary', [CreditController::class, 'customerSummary'])
        ->name('credits.customer-summary');
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
    Route::get('/credits/{ledgerEntry}/confirmation', [CreditController::class, 'confirmation'])
        ->name('credits.confirmation');

    Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::get('/payments/customers/{customer}/summary', [PaymentController::class, 'customerSummary'])
        ->name('payments.customer-summary');
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/{paymentBatchId}/confirmation', [PaymentController::class, 'confirmation'])
        ->name('payments.confirmation');

    Route::resource('customers', CustomerController::class)
        ->only(['index', 'create', 'store', 'edit', 'update']);

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show')
        ->missing(fn () => response()->view('customers.not-found', status: 404));
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
