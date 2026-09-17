<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Order;
use App\Support\Cart;
use App\Support\WhatsAppOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CartController extends Controller
{
    /**
     * Show the current storefront's cart.
     *
     * The business is always resolved from the subdomain (see
     * PublicStoreController), so this can only ever show that business's
     * own cart.
     */
    public function index(Business $business): View
    {
        $cart = new Cart($business);

        // Read the cart once: this is what re-validates every item against
        // the database (dropping anything deleted/unavailable), and count
        // and subtotal are derived from that same, already-validated set.
        $items = $cart->items();
        $subtotal = (float) $items->sum('subtotal');

        return view('cart.index', [
            'business' => $business,
            'items' => $items,
            'subtotal' => $subtotal,
            'cartCount' => (int) $items->sum('quantity'),
            'itemsWereRemoved' => $cart->wasReconciled(),
            'hasWhatsappNumber' => (bool) WhatsAppOrder::normalizeNumber($business->setting?->whatsapp_number),
        ]);
    }

    /**
     * Turn the current cart into an order, then hand off to WhatsApp.
     *
     * The cart is re-validated against the database here exactly as it is
     * for index() — nothing about product ownership, availability, or
     * price is ever trusted from the browser. The order (and its items)
     * are only committed, and the cart only cleared, once everything has
     * succeeded; a failure leaves the cart untouched so the customer can
     * simply try again.
     */
    public function checkout(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $cart = new Cart($business);
        $items = $cart->items();

        if ($items->isEmpty()) {
            return redirect()->route('cart.index', ['business' => $business->handle])
                ->with('error', 'Your cart is empty.');
        }

        $subtotal = (float) $items->sum('subtotal');

        try {
            $order = DB::transaction(function () use ($business, $items, $subtotal, $validated) {
                $order = $business->orders()->create([
                    'order_number' => Order::generateOrderNumber(),
                    'customer_name' => $validated['customer_name'] ?? null,
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'status' => Order::STATUS_PENDING,
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                ]);

                foreach ($items as $item) {
                    $order->items()->create([
                        'product_id' => $item['product']->id,
                        'product_name' => $item['product']->name,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['product']->price,
                        'subtotal' => $item['subtotal'],
                    ]);
                }

                return $order;
            });
        } catch (\Throwable $e) {
            return redirect()->route('cart.index', ['business' => $business->handle])
                ->with('error', 'Something went wrong creating your order. Please try again.');
        }

        $whatsappUrl = (new WhatsAppOrder($order->load('items')))->url();

        // Only clear the cart once the order has been successfully created.
        $cart->clear();

        if (! $whatsappUrl) {
            return redirect()->route('cart.index', ['business' => $business->handle])
                ->with('status', "Order {$order->order_number} was created, but this store hasn't set up WhatsApp ordering yet.");
        }

        return redirect($whatsappUrl);
    }

    /**
     * Add a product to the current storefront's cart.
     *
     * The product id from the request is never trusted on its own — it is
     * only ever looked up scoped to the resolved business, and only an
     * available product can be added.
     *
     * Responds with JSON for the storefront's async "Add to Cart" button
     * (see resources/js/app.js), or a normal redirect back for a plain
     * form submission when JavaScript is unavailable.
     */
    public function store(Request $request, Business $business, string $product): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QUANTITY],
        ]);

        $product = $business->products()->where('is_available', true)->findOrFail($product);

        $cart = new Cart($business);
        $cart->add($product, $validated['quantity'] ?? 1);

        $message = "{$product->name} added to cart.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'cartCount' => $cart->count(),
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Update a cart item's quantity. A quantity of 0 removes it.
     */
    public function update(Request $request, Business $business, string $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QUANTITY],
        ]);

        // A customer must always be able to remove or reduce an item even
        // if the product has since become unavailable, so availability is
        // not required here — only that it still belongs to this business.
        $product = $business->products()->findOrFail($product);

        (new Cart($business))->update($product->id, $validated['quantity']);

        return redirect()->route('cart.index', ['business' => $business->handle]);
    }

    /**
     * Remove a product from the cart.
     */
    public function destroy(Business $business, string $product): RedirectResponse
    {
        $product = $business->products()->findOrFail($product);

        (new Cart($business))->remove($product->id);

        return redirect()->route('cart.index', ['business' => $business->handle]);
    }

    /**
     * Empty the cart.
     */
    public function clear(Business $business): RedirectResponse
    {
        (new Cart($business))->clear();

        return redirect()->route('cart.index', ['business' => $business->handle]);
    }
}
