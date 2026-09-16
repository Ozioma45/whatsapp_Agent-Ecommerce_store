<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): string
    {
        return config('app.domain');
    }

    private function business(): Business
    {
        $owner = User::factory()->create();

        return Business::factory()->create(['owner_id' => $owner->id]);
    }

    private function url(Business $business, string $path = '', array $query = []): string
    {
        $url = 'http://'.$business->handle.'.'.$this->domain().'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    // --- Add to cart --------------------------------------------------

    public function test_a_customer_can_add_an_available_product_to_the_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['is_available' => true]);

        $this->post($this->url($business, "cart/{$product->id}"))
            ->assertRedirect();

        $this->get($this->url($business, 'cart'))
            ->assertSee($product->name);
    }

    public function test_adding_the_same_product_twice_increases_its_quantity(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['is_available' => true]);

        $this->post($this->url($business, "cart/{$product->id}"));
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->get($this->url($business, 'cart'))->assertSee('2');
    }

    public function test_an_unavailable_product_cannot_be_added(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['is_available' => false]);

        $this->post($this->url($business, "cart/{$product->id}"))->assertNotFound();

        $this->get($this->url($business, 'cart'))->assertSee('Your cart is empty.');
    }

    public function test_a_product_belonging_to_another_business_cannot_be_added(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $productB = Product::factory()->for($businessB)->create(['is_available' => true]);

        $this->post($this->url($businessA, "cart/{$productB->id}"))->assertNotFound();

        $this->get($this->url($businessA, 'cart'))->assertSee('Your cart is empty.');
    }

    // --- Cart display ---------------------------------------------------

    public function test_the_cart_displays_added_products(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Running Shoes']);

        $this->post($this->url($business, "cart/{$product->id}"));

        $this->get($this->url($business, 'cart'))->assertSee('Running Shoes');
    }

    public function test_the_cart_displays_the_correct_quantity(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();

        $this->post($this->url($business, "cart/{$product->id}"), ['quantity' => 4]);

        $this->get($this->url($business, 'cart'))->assertSee('4');
    }

    public function test_the_cart_calculates_the_correct_item_subtotal(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['price' => 25.00]);

        $this->post($this->url($business, "cart/{$product->id}"), ['quantity' => 3]);

        $this->get($this->url($business, 'cart'))->assertSee('75.00');
    }

    public function test_the_cart_calculates_the_correct_overall_subtotal(): void
    {
        $business = $this->business();
        $productA = Product::factory()->for($business)->create(['price' => 10.00]);
        $productB = Product::factory()->for($business)->create(['price' => 15.00]);

        $this->post($this->url($business, "cart/{$productA->id}"), ['quantity' => 2]); // 20.00
        $this->post($this->url($business, "cart/{$productB->id}"), ['quantity' => 1]); // 15.00

        $this->get($this->url($business, 'cart'))->assertSee('Subtotal: 35.00');
    }

    public function test_the_cart_count_is_correct(): void
    {
        $business = $this->business();
        $productA = Product::factory()->for($business)->create();
        $productB = Product::factory()->for($business)->create();

        $this->post($this->url($business, "cart/{$productA->id}"), ['quantity' => 2]);
        $this->post($this->url($business, "cart/{$productB->id}"), ['quantity' => 1]);

        $this->get($this->url($business, '/'))->assertSee('Cart (3)');
    }

    public function test_an_empty_cart_displays_correctly(): void
    {
        $business = $this->business();

        $this->get($this->url($business, 'cart'))
            ->assertSee('Your cart is empty.')
            ->assertSee('Continue shopping');
    }

    // --- Quantity ---------------------------------------------------------

    public function test_quantity_can_be_increased(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => 5]);

        $this->get($this->url($business, 'cart'))->assertSee('5');
    }

    public function test_quantity_can_be_decreased(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"), ['quantity' => 5]);

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => 2]);

        $this->get($this->url($business, 'cart'))->assertSee('2');
    }

    public function test_a_quantity_of_zero_removes_the_item(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => 0]);

        $this->get($this->url($business, 'cart'))->assertSee('Your cart is empty.');
    }

    public function test_quantity_cannot_be_negative(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => -1])
            ->assertSessionHasErrors('quantity');

        $this->get($this->url($business, 'cart'))->assertSee('1');
    }

    public function test_quantity_cannot_exceed_the_maximum(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => 100])
            ->assertSessionHasErrors('quantity');
    }

    public function test_an_invalid_quantity_is_rejected(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->patch($this->url($business, "cart/{$product->id}"), ['quantity' => 'not-a-number'])
            ->assertSessionHasErrors('quantity');
    }

    // --- Remove -------------------------------------------------------

    public function test_a_product_can_be_removed_from_the_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Running Shoes']);
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->delete($this->url($business, "cart/{$product->id}"));

        $this->get($this->url($business, 'cart'))->assertDontSee('Running Shoes');
    }

    public function test_removing_the_final_product_produces_an_empty_cart(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create();
        $this->post($this->url($business, "cart/{$product->id}"));

        $this->delete($this->url($business, "cart/{$product->id}"));

        $this->get($this->url($business, 'cart'))->assertSee('Your cart is empty.');
    }

    // --- Tenant isolation -----------------------------------------------

    public function test_business_a_cannot_add_business_bs_product(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $productB = Product::factory()->for($businessB)->create();

        $this->post($this->url($businessA, "cart/{$productB->id}"))->assertNotFound();
    }

    public function test_business_bs_storefront_does_not_display_business_as_cart_items(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $productA = Product::factory()->for($businessA)->create(['name' => 'Business A Product']);

        $this->post($this->url($businessA, "cart/{$productA->id}"));

        $this->get($this->url($businessB, 'cart'))
            ->assertDontSee('Business A Product')
            ->assertSee('Your cart is empty.');
    }

    public function test_cart_calculations_only_use_products_from_the_current_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        Product::factory()->for($businessA)->create(['price' => 1000.00]);
        $productB = Product::factory()->for($businessB)->create(['price' => 5.00]);

        $this->post($this->url($businessB, "cart/{$productB->id}"));

        $this->get($this->url($businessB, 'cart'))->assertSee('Subtotal: 5.00');
    }

    // --- Product changes --------------------------------------------------

    public function test_a_deleted_product_is_removed_from_the_cart_when_loaded(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Running Shoes']);
        $this->post($this->url($business, "cart/{$product->id}"));

        $product->delete();

        $this->get($this->url($business, 'cart'))
            ->assertDontSee('Running Shoes')
            ->assertSee('Your cart is empty.');
    }

    public function test_a_product_that_becomes_unavailable_is_removed_from_the_cart_when_loaded(): void
    {
        $business = $this->business();
        $product = Product::factory()->for($business)->create(['name' => 'Running Shoes', 'is_available' => true]);
        $this->post($this->url($business, "cart/{$product->id}"));

        $product->update(['is_available' => false]);

        $this->get($this->url($business, 'cart'))
            ->assertDontSee('Running Shoes')
            ->assertSee('Your cart is empty.');
    }
}
