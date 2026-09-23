<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The platform-wide overview. Unlike every business-owner controller,
     * admin controllers are intentionally NOT scoped to the authenticated
     * user's own business — the "admin" middleware (see bootstrap/app.php)
     * is what restricts this whole route group to administrators.
     */
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'businessCount' => Business::count(),
            'userCount' => User::count(),
            'orderCount' => Order::count(),
            'plans' => Plan::withCount('businesses')->orderBy('id')->get(),
        ]);
    }
}
