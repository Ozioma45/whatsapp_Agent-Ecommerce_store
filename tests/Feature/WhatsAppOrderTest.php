<?php

namespace Tests\Feature;

use App\Models\Business;
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

    private function url(Business $business, string $path = '', array $query = []): string
    {
        $url = 'http://'.$business->handle.'.'.$this->domain().'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    private function addToCart(Business $business, Product $product, int $quantity = 1): void
    {
        $this->post($this->url($business, "cart/{$product->id}"), ['quantity' => $quantity]);
    }

    // --- WhatsApp order generation ---------------------------------------

    public function test_the_order_via_whatsapp_button_appears_for_a_valid_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->get($this->url($business, 'cart'))->assertSee('Order via WhatsApp');
    }

    public function test_no_order_button_appears_for_an_empty_cart(): void
    {
        $business = $this->business();

        $this->get($this->url($business, 'cart'))->assertDontSee('Order via WhatsApp');
    }

    public function test_the_generated_url_uses_the_correct_business_whatsapp_number(): void
    {
        $business = $this->business('08012345678');
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $this->get($this->url($business, 'cart'))
            ->assertSee('https://wa.me/2348012345678?text=', false);
    }

    public function test_the_message_includes_the_business_name(): void
    {
        $business = $this->business();
        $business->update(['name' => 'Mike Shoes']);
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertSee(rawurlencode('Hello Mike Shoes'), false);
    }

    public function test_the_message_includes_correct_products_and_quantities(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Classic Sneakers']);
        $this->addToCart($business, $product, 3);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertSee(rawurlencode('Classic Sneakers'), false);
        $response->assertSee(rawurlencode('Qty: 3'), false);
    }

    public function test_the_message_uses_the_current_database_price(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 25000]);
        $this->addToCart($business, $product, 2);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertSee(rawurlencode('Price: ₦25,000.00'), false);
    }

    public function test_the_message_includes_correct_item_subtotals(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 25000]);
        $this->addToCart($business, $product, 2);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertSee(rawurlencode('Subtotal: ₦50,000.00'), false);
    }

    public function test_the_message_includes_the_correct_cart_total(): void
    {
        $business = $this->business();
        $productA = Product::factory()->for($business)->create(['price' => 25000]);
        $productB = Product::factory()->for($business)->create(['price' => 10000]);
        $this->addToCart($business, $productA, 2); // 50,000
        $this->addToCart($business, $productB, 1); // 10,000

        $response = $this->get($this->url($business, 'cart'));

        $response->assertSee(rawurlencode('Total: ₦60,000.00'), false);
    }

    public function test_the_message_is_correctly_url_encoded(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $html = $this->get($this->url($business, 'cart'))->getContent();

        preg_match('/https:\/\/wa\.me\/\d+\?text=([^"&]+)/', $html, $matches);

        $this->assertNotEmpty($matches, 'WhatsApp URL not found in response.');
        // The captured text must be a validly encoded string containing no
        // raw spaces or newlines — everything is percent-encoded.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~%]+$/', $matches[1]);
        $this->assertStringNotContainsString(' ', $matches[1]);
    }

    public function test_an_unavailable_product_is_not_included_in_the_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Discontinued Item']);
        $this->addToCart($business, $product);
        $product->update(['is_available' => false]);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertDontSee(rawurlencode('Discontinued Item'), false);
        $response->assertDontSee('Order via WhatsApp');
    }

    public function test_a_deleted_product_is_not_included_in_the_order(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Removed Item']);
        $this->addToCart($business, $product);
        $product->delete();

        $response = $this->get($this->url($business, 'cart'));

        $response->assertDontSee(rawurlencode('Removed Item'), false);
        $response->assertDontSee('Order via WhatsApp');
    }

    public function test_a_business_with_no_whatsapp_number_shows_no_order_button(): void
    {
        $business = $this->business(null);
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->get($this->url($business, 'cart'));

        $response->assertDontSee('Order via WhatsApp');
        $response->assertSee('set up WhatsApp ordering yet');
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_business_a_cannot_generate_an_order_containing_business_bs_products(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $productA = Product::factory()->for($businessA)->create(['name' => 'Business A Product']);
        Product::factory()->for($businessB)->create(['name' => 'Business B Product']);
        $this->addToCart($businessA, $productA);

        $response = $this->get($this->url($businessA, 'cart'));

        $response->assertSee(rawurlencode('Business A Product'), false);
        $response->assertDontSee(rawurlencode('Business B Product'), false);
    }

    public function test_business_a_and_business_b_each_use_their_own_whatsapp_number(): void
    {
        $businessA = $this->business('08011111111');
        $businessB = $this->business('08022222222');
        $productA = Product::factory()->for($businessA)->create();
        $productB = Product::factory()->for($businessB)->create();
        $this->addToCart($businessA, $productA);
        $this->addToCart($businessB, $productB);

        $this->get($this->url($businessA, 'cart'))->assertSee('https://wa.me/2348011111111?', false);
        $this->get($this->url($businessB, 'cart'))->assertSee('https://wa.me/2348022222222?', false);
    }

    public function test_the_destination_number_cannot_be_manipulated_through_request_data(): void
    {
        $business = $this->business('08011111111');
        $product = Product::factory()->for($business)->create();
        $this->addToCart($business, $product);

        $response = $this->get($this->url($business, 'cart', [
            'whatsapp_number' => '19999999999',
            'business_name' => 'Someone Else',
        ]));

        $response->assertSee('https://wa.me/2348011111111?', false);
        $response->assertDontSee('19999999999');
    }
}
