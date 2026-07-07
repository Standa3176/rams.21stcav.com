<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Laravel 11 application bootstrap.
 *
 * ⚠  If you already ran `composer create-project laravel/laravel .` and have
 *    a bootstrap/app.php, merge the withMiddleware() closure below into your
 *    existing file — do NOT overwrite the rest of the file.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ── Custom middleware aliases ─────────────────────────────────────────
        // 'admin' guards RAMS settings routes (GET/POST /rams/settings).
        // Any route that needs admin-only access can now use:
        //   Route::middleware(['auth', 'admin'])->...
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // ── Security headers (audit M-02) ─────────────────────────────────────
        // Attach hardening headers to every web response so the app self-
        // defends regardless of webserver config. See the middleware class
        // for the specific headers set + rationale per header.
        $middleware->web(append: [
            \App\Http\Middleware\SetSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
