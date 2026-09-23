<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows a simple maintenance page to public visitors (the landing page and
 * business storefronts) when maintenance_mode is on.
 *
 * Deliberately only attached to those public route groups in routes/web.php
 * — never to the authenticated dashboard or /admin — so admins (and
 * business owners managing their store) are never locked out by this.
 */
class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Setting::getBool(Setting::MAINTENANCE_MODE)) {
            return response()->view('maintenance', status: 503);
        }

        return $next($request);
    }
}
