<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * A session-based shopping cart for one business's storefront.
 *
 * Cart contents are stored under session key "cart.{business_id}", so a
 * customer's cart on one business's subdomain can never mix with another
 * business's cart even within the same session. Only a product id and
 * quantity are ever stored — price and product details always come fresh
 * from the database when the cart is read.
 */
class Cart
{
    /**
     * The maximum quantity allowed per product, as a simple abuse safeguard
     * rather than real inventory tracking.
     */
    public const MAX_QUANTITY = 99;

    /**
     * Whether the last call to items() dropped anything (a product that
     * was deleted or became unavailable since it was added to the cart).
     */
    private bool $reconciled = false;

    public function __construct(private readonly Business $business) {}

    /**
     * Add a product to the cart, increasing its quantity if already present.
     *
     * Callers must have already verified the product belongs to this
     * business and is available (see CartController::store()).
     */
    public function add(Product $product, int $quantity = 1): void
    {
        $items = $this->rawItems();
        $current = $items[$product->id] ?? 0;
        $items[$product->id] = min(self::MAX_QUANTITY, max(1, $current + $quantity));
        $this->putRawItems($items);
    }

    /**
     * Set a product's quantity. A quantity of zero or less removes it.
     */
    public function update(int $productId, int $quantity): void
    {
        $items = $this->rawItems();

        if ($quantity <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = min(self::MAX_QUANTITY, $quantity);
        }

        $this->putRawItems($items);
    }

    /**
     * Remove a product from the cart entirely.
     */
    public function remove(int $productId): void
    {
        $items = $this->rawItems();
        unset($items[$productId]);
        $this->putRawItems($items);
    }

    /**
     * Empty the cart.
     */
    public function clear(): void
    {
        $this->putRawItems([]);
    }

    /**
     * The cart's items resolved against the current database state.
     *
     * A product that has since been deleted or made unavailable is dropped
     * here (and the session pruned to match), and price/subtotal always
     * come from the product's current record — never from anything stored
     * client-side.
     *
     * @return Collection<int, array{product: Product, quantity: int, subtotal: float}>
     */
    public function items(): Collection
    {
        $raw = $this->rawItems();

        if ($raw === []) {
            $this->reconciled = false;

            return collect();
        }

        $products = $this->business->products()
            ->where('is_available', true)
            ->whereIn('id', array_keys($raw))
            ->get()
            ->keyBy('id');

        $valid = array_intersect_key($raw, $products->all());
        $this->reconciled = $valid !== $raw;

        if ($this->reconciled) {
            $this->putRawItems($valid);
        }

        return collect($valid)->map(fn (int $quantity, int $productId): array => [
            'product' => $products[$productId],
            'quantity' => $quantity,
            'subtotal' => (float) $products[$productId]->price * $quantity,
        ])->values();
    }

    /**
     * Whether the most recent call to items() removed something that had
     * been deleted or made unavailable since it was added.
     */
    public function wasReconciled(): bool
    {
        return $this->reconciled;
    }

    /**
     * Total item quantity across the cart (not the number of distinct products).
     */
    public function count(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /**
     * The cart's total value, calculated entirely from current product prices.
     */
    public function subtotal(): float
    {
        return (float) $this->items()->sum('subtotal');
    }

    /**
     * @return array<int, int> product id => quantity
     */
    private function rawItems(): array
    {
        return session($this->sessionKey(), []);
    }

    /**
     * @param  array<int, int>  $items
     */
    private function putRawItems(array $items): void
    {
        if ($items === []) {
            session()->forget($this->sessionKey());
        } else {
            session([$this->sessionKey() => $items]);
        }
    }

    private function sessionKey(): string
    {
        return "cart.{$this->business->id}";
    }
}
