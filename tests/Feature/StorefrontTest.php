<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StorefrontTest extends TestCase
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

    private function visit(Business $business, array $query = []): TestResponse
    {
        $url = 'http://'.$business->handle.'.'.$this->domain().'/';

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->get($url);
    }

    public function test_the_storefront_is_accessible_without_authentication(): void
    {
        $business = $this->business();

        $this->visit($business)->assertOk();
    }

    public function test_available_products_are_displayed(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['name' => 'Running Shoes', 'is_available' => true]);

        $this->visit($business)->assertSee('Running Shoes');
    }

    public function test_unavailable_products_are_not_displayed(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['name' => 'Discontinued Sandals', 'is_available' => false]);

        $this->visit($business)
            ->assertDontSee('Discontinued Sandals')
            ->assertSee('No products available yet.');
    }

    public function test_products_from_another_business_are_never_displayed(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        Product::factory()->for($businessB)->create(['name' => 'Business B Product']);

        $this->visit($businessA)->assertDontSee('Business B Product');
    }

    public function test_categories_are_scoped_to_the_current_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        Category::factory()->for($businessA)->create(['name' => 'Business A Category']);
        Category::factory()->for($businessB)->create(['name' => 'Business B Category']);

        $this->visit($businessA)
            ->assertSee('Business A Category')
            ->assertDontSee('Business B Category');
    }

    public function test_selecting_a_category_only_shows_products_in_that_category(): void
    {
        $business = $this->business();
        $shoes = Category::factory()->for($business)->create(['name' => 'Shoes']);
        $shirts = Category::factory()->for($business)->create(['name' => 'Shirts']);
        Product::factory()->for($business)->create(['name' => 'Sneakers', 'category_id' => $shoes->id]);
        Product::factory()->for($business)->create(['name' => 'T-Shirt', 'category_id' => $shirts->id]);

        $this->visit($business, ['category' => $shoes->id])
            ->assertSee('Sneakers')
            ->assertDontSee('T-Shirt');
    }

    public function test_a_category_id_belonging_to_another_business_is_ignored_by_the_filter(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $categoryB = Category::factory()->for($businessB)->create();
        Product::factory()->for($businessA)->create(['name' => 'Business A Product']);

        // Passing another business's category id must not error and must
        // not affect this storefront — it simply shows all of A's products.
        $this->visit($businessA, ['category' => $categoryB->id])
            ->assertOk()
            ->assertSee('Business A Product');
    }

    public function test_the_storefront_shows_an_empty_state_when_there_are_no_products(): void
    {
        $business = $this->business();

        $this->visit($business)->assertSee('No products available yet.');
    }

    public function test_the_storefront_works_when_there_are_no_categories(): void
    {
        $business = $this->business();
        Product::factory()->for($business)->create(['name' => 'Running Shoes']);

        $this->visit($business)
            ->assertOk()
            ->assertSee('Running Shoes');
    }

    public function test_the_storefront_works_without_a_logo(): void
    {
        $business = $this->business();
        $business->setting()->create(['logo' => null]);

        $this->visit($business)->assertOk()->assertSee($business->name);
    }

    public function test_the_storefront_works_without_a_description(): void
    {
        $business = $this->business();
        $business->setting()->create(['description' => null]);

        $this->visit($business)->assertOk()->assertSee($business->name);
    }

    public function test_business_as_storefront_cannot_display_business_bs_categories(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        Category::factory()->for($businessB)->create(['name' => 'Beauty Products']);

        $this->visit($businessA)->assertDontSee('Beauty Products');
    }
}
