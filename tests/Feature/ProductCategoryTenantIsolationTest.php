<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    public function test_business_a_cannot_view_business_bs_categories(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $categoryB = Category::factory()->for($businessB)->create(['name' => 'Business B Category']);

        $this->actingAs($ownerA)->get('/categories')->assertDontSee('Business B Category');
        $this->actingAs($ownerA)->get("/categories/{$categoryB->id}/edit")->assertNotFound();
    }

    public function test_business_a_cannot_edit_business_bs_category(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $categoryB = Category::factory()->for($businessB)->create(['name' => 'Original Name']);

        $response = $this->actingAs($ownerA)->put("/categories/{$categoryB->id}", ['name' => 'Hijacked']);

        $response->assertNotFound();
        $this->assertSame('Original Name', $categoryB->fresh()->name);
    }

    public function test_business_a_cannot_delete_business_bs_category(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $categoryB = Category::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA)->delete("/categories/{$categoryB->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $categoryB->id]);
    }

    public function test_business_a_cannot_view_business_bs_products(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $productB = Product::factory()->for($businessB)->create(['name' => 'Business B Product']);

        $this->actingAs($ownerA)->get('/products')->assertDontSee('Business B Product');
        $this->actingAs($ownerA)->get("/products/{$productB->id}/edit")->assertNotFound();
    }

    public function test_business_a_cannot_edit_business_bs_product(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $productB = Product::factory()->for($businessB)->create(['name' => 'Original Name']);

        $response = $this->actingAs($ownerA)->put("/products/{$productB->id}", [
            'name' => 'Hijacked',
            'price' => '1.00',
        ]);

        $response->assertNotFound();
        $this->assertSame('Original Name', $productB->fresh()->name);
    }

    public function test_business_a_cannot_delete_business_bs_product(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $productB = Product::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA)->delete("/products/{$productB->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $productB->id]);
    }

    public function test_a_product_cannot_be_assigned_to_another_businesss_category(): void
    {
        [$ownerA, $businessA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $categoryB = Category::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA)->post('/products', [
            'name' => 'Running Shoes',
            'price' => '49.99',
            'category_id' => $categoryB->id,
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertDatabaseMissing('products', ['business_id' => $businessA->id, 'name' => 'Running Shoes']);
    }

    public function test_an_existing_product_cannot_be_reassigned_to_another_businesss_category(): void
    {
        [$ownerA, $businessA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $categoryB = Category::factory()->for($businessB)->create();
        $product = Product::factory()->for($businessA)->create();

        $response = $this->actingAs($ownerA)->put("/products/{$product->id}", [
            'name' => $product->name,
            'price' => (string) $product->price,
            'category_id' => $categoryB->id,
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertNull($product->fresh()->category_id);
    }
}
