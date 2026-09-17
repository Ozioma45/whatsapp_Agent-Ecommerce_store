<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * List the authenticated user's own business orders.
     */
    public function index(Request $request): View
    {
        $orders = $request->user()->business
            ->orders()
            ->latest()
            ->get();

        return view('orders.index', ['orders' => $orders]);
    }

    /**
     * Show one of the authenticated user's own orders.
     *
     * The order is always looked up scoped to the authenticated user's own
     * business, so an id belonging to another business simply 404s — this
     * is what prevents one business from viewing another's order by URL.
     */
    public function show(Request $request, string $order): View
    {
        $order = $request->user()->business->orders()->with('items')->findOrFail($order);

        return view('orders.show', ['order' => $order]);
    }

    /**
     * Update the status of one of the authenticated user's own orders.
     */
    public function update(Request $request, string $order): RedirectResponse
    {
        $order = $request->user()->business->orders()->findOrFail($order);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $order->update($validated);

        return redirect()->route('orders.show', $order)->with('status', 'Order status updated.');
    }
}
