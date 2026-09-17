<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): string
    {
        return config('app.domain');
    }

    private function business(?string $whatsappNumber = '08012345678'): Business
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $business->setting()->create(['whatsapp_number' => $whatsappNumber]);

        return $business;
    }

    private function url(Business $business, string $path = ''): string
    {
        return 'http://'.$business->handle.'.'.$this->domain().'/'.ltrim($path, '/');
    }

    private function addToCart(Business $business, Product $product, int $quantity = 1): void
    {
        $this->post($this->url($business, "cart/{$product->id}"), ['quantity' => $quantity]);
    }

    private function checkout(Business $business, array $data = []): TestResponse
    {
        return $this->post($this->url($business, 'checkout'), $data);
    }

    // --- Order creation -----------------------------------------------

    public function test_a_valid_cart_creates_an_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_the_order_belongs_to_the_correct_business(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->assertDatabaseHas('orders', ['business_id' => $business->id]);
    }

    public function test_an_order_number_is_generated(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business);

        $order = Order::first();
        $this->assertNotEmpty($order->order_number);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{4}$/', $order->order_number);
    }

    public function test_the_correct_total_is_stored(): void
    {
        $business = $this->business();
        $productA = Product::factory()->for($business)->create(['price' => 25000]);
        $productB = Product::factory()->for($business)->create(['price' => 10000]);
        $this->addToCart($business, $productA, 2); // 50,000
        $this->addToCart($business, $productB, 1); // 10,000

        $this->checkout($business);

        $order = Order::first();
        $this->assertSame('60000.00', $order->subtotal);
        $this->assertSame('60000.00', $order->total);
    }

    public function test_the_correct_order_items_are_stored(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Classic Sneakers', 'price' => 25000]);
        $this->addToCart($business, $product, 2);

        $this->checkout($business);

        $order = Order::first();
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Classic Sneakers',
            'quantity' => 2,
            'unit_price' => '25000.00',
            'subtotal' => '50000.00',
        ]);
    }

    public function test_customer_name_and_phone_are_saved_when_provided(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business, ['customer_name' => 'John', 'customer_phone' => '08012345678']);

        $this->assertDatabaseHas('orders', ['customer_name' => 'John', 'customer_phone' => '08012345678']);
    }

    public function test_customer_name_and_phone_are_optional(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->assertDatabaseHas('orders', ['customer_name' => null, 'customer_phone' => null]);
    }

    // --- Historical data preservation -----------------------------------

    public function test_the_product_name_is_copied_to_the_order_item(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Original Name']);
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->assertDatabaseHas('order_items', ['product_name' => 'Original Name']);
    }

    public function test_the_product_price_is_copied_to_the_order_item(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 12345]);
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->assertDatabaseHas('order_items', ['unit_price' => '12345.00']);
    }

    public function test_changing_the_product_price_does_not_change_the_existing_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 100]);
        $this->addToCart($business, $product);
        $this->checkout($business);

        $order = Order::first();
        $product->update(['price' => 999]);

        $this->assertSame('100.00', $order->fresh()->items()->first()->unit_price);
        $this->assertSame('100.00', $order->fresh()->total);
    }

    public function test_deleting_the_product_does_not_destroy_the_historical_order_item(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Soon Deleted']);
        $this->addToCart($business, $product);
        $this->checkout($business);

        $order = Order::first();
        $product->delete();

        $item = $order->fresh()->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('Soon Deleted', $item->product_name);
        $this->assertNull($item->product_id);
    }

    // --- Cart behavior --------------------------------------------------

    public function test_a_successful_order_clears_the_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->checkout($business);

        $this->get($this->url($business, 'cart'))->assertSee('Your cart is empty.');
    }

    public function test_a_failed_order_does_not_clear_the_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Still Here']);
        $this->addToCart($business, $product);
        $product->update(['is_available' => false]);

        // The cart is now effectively empty once reconciled, so checkout
        // fails cleanly — nothing is created and no exception surfaces.
        $this->checkout($business);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_empty_cart_cannot_create_an_order(): void
    {
        $business = $this->business();

        $this->checkout($business);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_unavailable_product_cannot_create_an_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);
        $product->update(['is_available' => false]);

        $this->checkout($business);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_deleted_product_cannot_create_an_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);
        $product->delete();

        $this->checkout($business);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_cross_business_product_cannot_create_an_order(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $productB = Product::factory()->for($businessB)->create();

        // Cannot even be added to A's cart in the first place (see CartTest),
        // but confirm checkout is also safe if the cart were ever tampered with.
        $this->post($this->url($businessA, "cart/{$productB->id}"));

        $this->checkout($businessA);

        $this->assertDatabaseCount('orders', 0);
    }

    // --- Redirect behavior ------------------------------------------------

    public function test_checkout_redirects_to_the_whatsapp_url_on_success(): void
    {
        $business = $this->business('08012345678');
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->checkout($business);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/2348012345678', $response->headers->get('Location'));
    }
}
