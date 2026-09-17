<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppOrderTest extends TestCase
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

    /**
     * Add the given product to the cart, check out, and return the decoded
     * text of the resulting wa.me message (or null if none was generated).
     */
    private function checkoutAndDecodeMessage(Business $business, array $data = []): ?string
    {
        $response = $this->post($this->url($business, 'checkout'), $data);
        $location = $response->headers->get('Location');

        if (! $location || ! str_starts_with($location, 'https://wa.me/')) {
            return null;
        }

        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['text'] ?? null;
    }

    // --- WhatsApp order generation ---------------------------------------

    public function test_checkout_redirects_to_the_correct_business_whatsapp_number(): void
    {
        $business = $this->business('08012345678');
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->post($this->url($business, 'checkout'));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/2348012345678?', $response->headers->get('Location'));
    }

    public function test_the_message_includes_the_business_name(): void
    {
        $business = $this->business();
        $business->update(['name' => 'Mike Shoes']);
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $message = $this->checkoutAndDecodeMessage($business);

        $this->assertStringContainsString('Hello Mike Shoes, I would like to place an order.', $message);
    }

    public function test_the_message_includes_the_order_number(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $message = $this->checkoutAndDecodeMessage($business);
        $order = Order::first();

        $this->assertStringContainsString("Order: {$order->order_number}", $message);
    }

    public function test_the_message_includes_correct_products_and_quantities(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Classic Sneakers']);
        $this->addToCart($business, $product, 3);

        $message = $this->checkoutAndDecodeMessage($business);

        $this->assertStringContainsString('Classic Sneakers', $message);
        $this->assertStringContainsString('Qty: 3', $message);
    }

    public function test_the_message_uses_the_saved_order_prices(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 25000]);
        $this->addToCart($business, $product, 2);

        $message = $this->checkoutAndDecodeMessage($business);

        $this->assertStringContainsString('Price: ₦25,000.00', $message);
        $this->assertStringContainsString('Subtotal: ₦50,000.00', $message);
    }

    public function test_the_message_includes_the_correct_total(): void
    {
        $business = $this->business();
        $productA = Product::factory()->for($business)->create(['price' => 25000]);
        $productB = Product::factory()->for($business)->create(['price' => 10000]);
        $this->addToCart($business, $productA, 2); // 50,000
        $this->addToCart($business, $productB, 1); // 10,000

        $message = $this->checkoutAndDecodeMessage($business);

        $this->assertStringContainsString('Total: ₦60,000.00', $message);
    }

    public function test_the_message_includes_customer_details_when_provided(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $message = $this->checkoutAndDecodeMessage($business, [
            'customer_name' => 'John',
            'customer_phone' => '08012345678',
        ]);

        $this->assertStringContainsString('Customer:', $message);
        $this->assertStringContainsString('Name: John', $message);
        $this->assertStringContainsString('WhatsApp: 08012345678', $message);
    }

    public function test_no_customer_block_when_no_details_are_provided(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $message = $this->checkoutAndDecodeMessage($business);

        $this->assertStringNotContainsString('Customer:', $message);
    }

    public function test_the_message_is_correctly_url_encoded(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->post($this->url($business, 'checkout'));
        $location = $response->headers->get('Location');

        preg_match('/\?text=([^&]+)/', $location, $matches);

        $this->assertNotEmpty($matches, 'WhatsApp URL not found in redirect.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~%]+$/', $matches[1]);
        $this->assertStringNotContainsString(' ', $matches[1]);
    }

    public function test_an_empty_cart_cannot_generate_an_order_or_message(): void
    {
        $business = $this->business();

        $response = $this->post($this->url($business, 'checkout'));

        $response->assertRedirect(route('cart.index', ['business' => $business->handle]));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_business_with_no_whatsapp_number_still_creates_the_order(): void
    {
        $business = $this->business(null);
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->post($this->url($business, 'checkout'));

        $this->assertDatabaseCount('orders', 1);
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_business_a_and_business_b_each_use_their_own_whatsapp_number(): void
    {
        $businessA = $this->business('08011111111');
        $businessB = $this->business('08022222222');
        $productA = Product::factory()->for($businessA)->create();
        $productB = Product::factory()->for($businessB)->create();
        $this->addToCart($businessA, $productA);
        $this->addToCart($businessB, $productB);

        $responseA = $this->post($this->url($businessA, 'checkout'));
        $responseB = $this->post($this->url($businessB, 'checkout'));

        $this->assertStringStartsWith('https://wa.me/2348011111111?', $responseA->headers->get('Location'));
        $this->assertStringStartsWith('https://wa.me/2348022222222?', $responseB->headers->get('Location'));
    }

    public function test_the_destination_number_cannot_be_manipulated_through_request_data(): void
    {
        $business = $this->business('08011111111');
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->post($this->url($business, 'checkout'), [
            'whatsapp_number' => '19999999999',
            'business_id' => 999999,
        ]);

        $this->assertStringStartsWith('https://wa.me/2348011111111?', $response->headers->get('Location'));
    }
}
