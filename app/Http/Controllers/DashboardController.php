<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the authenticated business owner's dashboard.
     *
     * The business is always resolved from the authenticated user's own
     * relationship, never from a route or request parameter, so a business
     * can only ever see its own data.
     */
    public function __invoke(Request $request): View
    {
        $business = $request->user()->business()->with('setting')->firstOrFail();

        return view('dashboard', ['business' => $business]);
    }
}
