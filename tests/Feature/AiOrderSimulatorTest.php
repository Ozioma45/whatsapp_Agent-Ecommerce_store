<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppConversationMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The extended conversation simulator: the full order flow (ask, quantity,
 * review, confirm, cancel, reset), always via a session-only draft — never
 * a real WhatsAppConversation/WhatsAppOrderDraft, and never a real Order.
 */
class AiOrderSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function eligibleBusiness(): Business
    {
        $owner = User::factory()->create(['role' => User::ROLE_BUSINESS_OWNER]);
        $business = Business::factory()->create([
            'owner_id' => $owner->id,
            'plan_id' => Plan::where('slug', 'premium')->firstOrFail()->id,
        ]);
        $business->aiAssistantSettings()->update(['enabled' => true]);

        return $business->fresh();
    }

    private function simulate(Business $business, string $message)
    {
        return $this->actingAs($business->owner)->followingRedirects()
            ->post('/ai-assistant/simulate', ['message' => $message]);
    }

    public function test_a_full_order_flow_can_be_simulated_end_to_end_without_a_real_order(): void
    {
        Http::fake();
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Red Cap', 'price' => 5]);

        $this->simulate($business, '2 Blue Sneaker')
            ->assertSee('Blue Sneaker')
            ->assertSee('Current simulated draft', false);

        $this->simulate($business, 'Red Cap')->assertSee('Red Cap');

        $this->simulate($business, 'checkout')->assertSee('name', false);
        $this->simulate($business, 'Jane Simulated')->assertSee('CONFIRM');

        $response = $this->simulate($business, 'confirm');
        $response->assertSee('Simulated order', false);
        $response->assertSee('No real order was created');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, WhatsAppConversationMessage::count());
        $this->assertSame(0, $business->whatsAppConversations()->count());
        Http::assertNothingSent();
    }

    public function test_the_simulator_can_be_cancelled(): void
    {
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->simulate($business, 'Blue Sneaker');
        $response = $this->simulate($business, 'cancel');

        $response->assertSee('cancelled');
        $this->assertSame(0, Order::count());
    }

    public function test_an_unavailable_product_is_rejected_in_simulation(): void
    {
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Hidden Item', 'is_available' => false]);

        // Falls through to the deterministic fallback reply rather than
        // being added to the draft.
        $response = $this->simulate($business, 'I want the Hidden Item');

        $response->assertDontSee('Added');
    }

    public function test_resetting_the_simulation_clears_the_draft(): void
    {
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->simulate($business, 'Blue Sneaker');
        $this->actingAs($business->owner)->post('/ai-assistant/simulate/reset')->assertRedirect('/ai-assistant');

        $response = $this->simulate($business, 'checkout');
        $response->assertSee('anything available yet');
    }

    public function test_unauthorized_users_cannot_reach_the_reset_action(): void
    {
        $this->post('/ai-assistant/simulate/reset')->assertRedirect('/login');

        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'standard')->firstOrFail()->id]);
        $this->actingAs($business->owner)->post('/ai-assistant/simulate/reset')->assertForbidden();
    }

    public function test_a_simulated_draft_never_leaks_between_businesses(): void
    {
        $a = $this->eligibleBusiness();
        $b = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $a->id, 'name' => 'A Product', 'price' => 10]);
        Product::factory()->create(['business_id' => $b->id, 'name' => 'B Product', 'price' => 10]);

        $this->simulate($a, 'A Product');

        $response = $this->simulate($b, 'checkout');
        $response->assertSee('anything available yet');
        $response->assertDontSee('A Product');
    }
}
