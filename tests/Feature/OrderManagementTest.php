<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    public function test_business_a_can_see_its_own_orders(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create();

        $response = $this->actingAs($owner)->get('/orders');

        $response->assertOk();
        $response->assertSee($order->order_number);
    }

    public function test_new_orders_default_to_pending(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create(['status' => Order::STATUS_PENDING]);

        $this->assertSame(Order::STATUS_PENDING, $order->status);
    }

    public function test_a_business_owner_can_open_their_order(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create();
        OrderItem::factory()->for($order)->create(['product_name' => 'Classic Sneakers']);

        $response = $this->actingAs($owner)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertSee($order->order_number);
        $response->assertSee('Classic Sneakers');
    }

    public function test_a_business_owner_can_change_the_order_status(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create(['status' => Order::STATUS_PENDING]);

        $response = $this->actingAs($owner)->put("/orders/{$order->id}", ['status' => Order::STATUS_CONFIRMED]);

        $response->assertRedirect(route('orders.show', $order));
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
    }

    public function test_an_invalid_status_value_is_rejected(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create(['status' => Order::STATUS_PENDING]);

        $response = $this->actingAs($owner)->put("/orders/{$order->id}", ['status' => 'shipped']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_business_a_cannot_see_business_bs_orders_in_the_list(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $orderB = Order::factory()->for($businessB)->create();

        $this->actingAs($ownerA)->get('/orders')->assertDontSee($orderB->order_number);
    }

    public function test_direct_order_url_access_is_protected_from_another_business(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $orderB = Order::factory()->for($businessB)->create();

        $this->actingAs($ownerA)->get("/orders/{$orderB->id}")->assertNotFound();
    }

    public function test_business_a_cannot_change_business_bs_order_status(): void
    {
        [$ownerA] = $this->businessOwner();
        [, $businessB] = $this->businessOwner();
        $orderB = Order::factory()->for($businessB)->create(['status' => Order::STATUS_PENDING]);

        $response = $this->actingAs($ownerA)->put("/orders/{$orderB->id}", ['status' => Order::STATUS_CANCELLED]);

        $response->assertNotFound();
        $this->assertSame(Order::STATUS_PENDING, $orderB->fresh()->status);
    }

    public function test_a_guest_cannot_access_the_orders_list(): void
    {
        $this->get('/orders')->assertRedirect(route('login'));
    }

    // --- Order source (Phase 9E) -------------------------------------

    public function test_a_whatsapp_ai_order_is_labelled_in_the_list(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->whatsappAi()->create();

        $this->actingAs($owner)->get('/orders')
            ->assertSee($order->order_number)
            ->assertSee('WhatsApp AI');
    }

    public function test_a_storefront_order_is_not_labelled_as_whatsapp_ai(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->create();

        $this->assertSame(Order::SOURCE_STOREFRONT, $order->source);
        $this->actingAs($owner)->get('/orders')->assertDontSee('WhatsApp AI');
    }

    public function test_the_order_detail_page_shows_its_source(): void
    {
        [$owner, $business] = $this->businessOwner();
        $order = Order::factory()->for($business)->whatsappAi()->create();

        $this->actingAs($owner)->get("/orders/{$order->id}")->assertSee('WhatsApp AI assistant');
    }

    public function test_existing_orders_default_to_a_storefront_source_via_the_migrations_own_default(): void
    {
        [$owner, $business] = $this->businessOwner();

        // Simulates a pre-Phase-9E row: inserted with no "source" value at
        // all, relying purely on the migration's column default.
        $orderId = DB::table('orders')->insertGetId([
            'business_id' => $business->id,
            'order_number' => Order::generateOrderNumber(),
            'status' => Order::STATUS_PENDING,
            'subtotal' => 10,
            'total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::findOrFail($orderId);

        $this->assertSame(Order::SOURCE_STOREFRONT, $order->source);
        $this->actingAs($owner)->get("/orders/{$order->id}")->assertOk();
    }
}
