<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\Cart;
use App\Support\WhatsAppOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'whatsappUrl' => (new WhatsAppOrder($business, $items, $subtotal))->url(),
        ]);
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
