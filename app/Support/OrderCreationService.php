<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place an Order (and its items) is ever created from a validated
 * set of cart-like line items. Both the storefront checkout
 * (CartController) and the WhatsApp AI order flow
 * (App\Support\Ai\Orders\OrderConversationHandler) call this, so the same
 * rules and the same transaction apply to every order regardless of where
 * it came from — this never trusts anything about price or availability
 * beyond what the caller already resolved from the database.
 */
class OrderCreationService
{
    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $items
     */
    public function create(
        Business $business,
        Collection $items,
        string $customerName,
        string $customerPhone,
        string $source = Order::SOURCE_STOREFRONT,
    ): Order {
        $subtotal = (float) $items->sum('subtotal');

        return DB::transaction(function () use ($business, $items, $subtotal, $customerName, $customerPhone, $source) {
            $order = $business->orders()->create([
                'order_number' => Order::generateOrderNumber(),
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'source' => $source,
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
    }
}
