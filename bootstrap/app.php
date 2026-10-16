<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureStoreIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureAccountIsActive::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'store.active' => EnsureStoreIsActive::class,
        ]);

        $middleware->redirectUsersTo(fn (Request $request): string => $request->user()?->isSuperAdmin()
            ? route('admin.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash('app_password');
    })->create();
