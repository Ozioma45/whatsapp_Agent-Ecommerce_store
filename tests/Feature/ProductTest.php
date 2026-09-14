<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    public function test_a_business_owner_can_create_a_product(): void
    {
        [$owner, $business] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => 'Running Shoes',
            'description' => 'Lightweight running shoes.',
            'price' => '49.99',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Running Shoes',
            'price' => 49.99,
        ]);
    }

    public function test_a_business_owner_can_view_their_products(): void
    {
        [$owner, $business] = $this->businessOwner();
        Product::factory()->for($business)->create(['name' => 'Running Shoes']);

        $response = $this->actingAs($owner)->get('/products');

        $response->assertOk();
        $response->assertSee('Running Shoes');
    }

    public function test_a_business_owner_can_edit_their_product(): void
    {
        [$owner, $business] = $this->businessOwner();
        $product = Product::factory()->for($business)->create(['name' => 'Running Shoes', 'price' => 49.99]);

        $response = $this->actingAs($owner)->put("/products/{$product->id}", [
            'name' => 'Trail Running Shoes',
            'price' => '59.99',
        ]);

        $response->assertRedirect(route('products.index'));
        $product->refresh();
        $this->assertSame('Trail Running Shoes', $product->name);
        $this->assertSame('59.99', $product->price);
    }

    public function test_a_business_owner_can_delete_their_product(): void
    {
        Storage::fake('public');
        [$owner, $business] = $this->businessOwner();
        $product = Product::factory()->for($business)->create(['image' => 'products/shoe.png']);
        Storage::disk('public')->put('products/shoe.png', 'fake-image-content');

        $response = $this->actingAs($owner)->delete("/products/{$product->id}");

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing('products/shoe.png');
    }

    public function test_a_product_can_exist_without_a_category(): void
    {
        [$owner, $business] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => 'Running Shoes',
            'price' => '49.99',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'category_id' => null]);
    }

    public function test_a_product_can_be_assigned_to_the_owners_category(): void
    {
        [$owner, $business] = $this->businessOwner();
        $category = Category::factory()->for($business)->create();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => 'Running Shoes',
            'price' => '49.99',
            'category_id' => $category->id,
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'category_id' => $category->id]);
    }

    public function test_product_image_upload_works(): void
    {
        Storage::fake('public');
        [$owner, $business] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => 'Running Shoes',
            'price' => '49.99',
            'image' => UploadedFile::fake()->image('shoe.png'),
        ]);

        $response->assertRedirect(route('products.index'));
        $product = Product::where('business_id', $business->id)->firstOrFail();
        $this->assertNotNull($product->image);
        Storage::disk('public')->assertExists($product->image);
    }

    public function test_an_unavailable_product_can_be_saved(): void
    {
        [$owner, $business] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => 'Running Shoes',
            'price' => '49.99',
            // is_available intentionally omitted, as an unchecked checkbox would be.
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'is_available' => false]);
    }

    public function test_invalid_product_data_is_rejected(): void
    {
        [$owner] = $this->businessOwner();

        $response = $this->actingAs($owner)->post('/products', [
            'name' => '',
            'price' => 'not-a-number',
        ]);

        $response->assertSessionHasErrors(['name', 'price']);
        $this->assertDatabaseCount('products', 0);
    }
}
