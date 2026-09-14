<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    public function test_a_business_owner_can_create_a_category(): void
    {
        [$owner, $business] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/categories', ['name' => 'Sneakers']);

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseHas('categories', [
            'business_id' => $business->id,
            'name' => 'Sneakers',
        ]);
    }

    public function test_a_business_owner_can_view_their_categories(): void
    {
        [$owner, $business] = $this->businessOwner();
        Category::factory()->for($business)->create(['name' => 'Sneakers']);

        $response = $this->actingAs($owner)->get('/categories');

        $response->assertOk();
        $response->assertSee('Sneakers');
    }

    public function test_a_business_owner_can_edit_their_category(): void
    {
        [$owner, $business] = $this->businessOwner();
        $category = Category::factory()->for($business)->create(['name' => 'Sneakers']);

        $response = $this->actingAs($owner)->put("/categories/{$category->id}", ['name' => 'Shoes']);

        $response->assertRedirect(route('categories.index'));
        $this->assertSame('Shoes', $category->fresh()->name);
    }

    public function test_a_business_owner_can_delete_their_category(): void
    {
        [$owner, $business] = $this->businessOwner();
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($business)->create(['category_id' => $category->id]);

        $response = $this->actingAs($owner)->delete("/categories/{$category->id}");

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);

        // The product is not deleted, only detached from the category.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_duplicate_category_names_are_rejected_within_the_same_business(): void
    {
        [$owner, $business] = $this->businessOwner();
        Category::factory()->for($business)->create(['name' => 'Sneakers']);

        $response = $this->actingAs($owner)->post('/categories', ['name' => 'Sneakers']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Category::where('business_id', $business->id)->where('name', 'Sneakers')->count());
    }

    public function test_the_same_category_name_is_allowed_for_different_businesses(): void
    {
        [$ownerA, $businessA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        Category::factory()->for($businessB)->create(['name' => 'Sneakers']);

        $response = $this->actingAs($ownerA)->post('/categories', ['name' => 'Sneakers']);

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseHas('categories', ['business_id' => $businessA->id, 'name' => 'Sneakers']);
        $this->assertDatabaseHas('categories', ['business_id' => $businessB->id, 'name' => 'Sneakers']);
    }
}
