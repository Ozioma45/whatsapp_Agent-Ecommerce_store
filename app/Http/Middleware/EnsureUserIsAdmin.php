<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to platform administrators.
 *
 * Server-side only: the check is a plain database-backed column read via
 * User::isAdmin(), never anything supplied by the client. Pair with the
 * "auth" middleware (an unauthenticated visitor has no user to check).
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
