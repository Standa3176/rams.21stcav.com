<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware alias: 'admin'
 *
 * Registered in bootstrap/app.php:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias(['admin' => EnsureUserIsAdmin::class]);
 *   })
 *
 * Applied to routes that require admin access (e.g. RAMS settings):
 *   Route::middleware(['auth', 'admin'])->group(...)
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'This area requires administrator access.');
        }

        return $next($request);
    }
}
