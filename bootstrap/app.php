<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are configured from config('app.trusted_proxies')
        // in AppServiceProvider::boot() — this closure runs before the
        // config repository is loaded, so it cannot read the setting.
        // Default: trust none, otherwise any client could spoof
        // X-Forwarded-For and defeat every per-IP rate limiter.
        $middleware->web(prepend: [
            EnsureInstalled::class,
        ]);
        $middleware->web(append: [
            // Binds the session to the user's password hash — every other
            // session dies as soon as the password changes.
            AuthenticateSession::class,
            EnsureActive::class,
            SetLocale::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
