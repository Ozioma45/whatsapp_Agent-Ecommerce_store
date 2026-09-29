<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppConversationMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationSimulatorTest extends TestCase
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

    public function test_guests_cannot_reach_the_simulator(): void
    {
        $this->post('/ai-assistant/simulate', ['message' => 'hi'])->assertRedirect('/login');
    }

    public function test_a_non_eligible_business_is_refused(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'standard')->firstOrFail()->id]);

        $this->actingAs($business->owner)->post('/ai-assistant/simulate', ['message' => 'hi'])
            ->assertForbidden();
    }

    public function test_an_admin_with_no_business_of_their_own_gets_a_404(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/ai-assistant/simulate', ['message' => 'hi'])->assertNotFound();
    }

    public function test_the_label_and_form_are_visible_on_the_settings_page(): void
    {
        $business = $this->eligibleBusiness();

        $this->actingAs($business->owner)->get('/ai-assistant')
            ->assertOk()
            ->assertSee('Simulation — no real WhatsApp message sent')
            ->assertSee('Conversation simulator');
    }

    public function test_an_eligible_owner_can_simulate_a_question_about_a_real_product(): void
    {
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20, 'is_available' => true]);

        $response = $this->actingAs($business->owner)->followingRedirects()
            ->post('/ai-assistant/simulate', ['message' => 'Do you have the Blue Sneaker?']);

        $response->assertOk()->assertSee('Blue Sneaker');
    }

    public function test_simulating_a_nonexistent_product_does_not_invent_one(): void
    {
        $business = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Real Product', 'is_available' => true]);

        $response = $this->actingAs($business->owner)->followingRedirects()
            ->post('/ai-assistant/simulate', ['message' => 'Do you sell something we do not stock?']);

        $response->assertOk()
            ->assertSee('I don&#039;t have information about that. Here&#039;s what we currently have available: Real Product.', false)
            ->assertSee('Real Product');
    }

    public function test_a_disabled_assistant_reports_a_disabled_outcome(): void
    {
        $business = $this->eligibleBusiness();
        $business->aiAssistantSettings()->update(['enabled' => false]);

        $response = $this->actingAs($business->owner)->followingRedirects()
            ->post('/ai-assistant/simulate', ['message' => 'hi']);

        $response->assertOk()->assertSee('currently disabled');
    }

    public function test_an_empty_message_is_rejected_by_validation(): void
    {
        $business = $this->eligibleBusiness();

        $this->actingAs($business->owner)->post('/ai-assistant/simulate', ['message' => ''])
            ->assertSessionHasErrors('message');
    }

    public function test_the_simulator_never_calls_the_real_outgoing_whatsapp_service(): void
    {
        Http::fake();
        $business = $this->eligibleBusiness();

        $this->actingAs($business->owner)->post('/ai-assistant/simulate', ['message' => 'hi']);

        Http::assertNothingSent();
    }

    public function test_the_simulator_does_not_persist_a_conversation(): void
    {
        $business = $this->eligibleBusiness();

        $this->actingAs($business->owner)->post('/ai-assistant/simulate', ['message' => 'hi']);

        $this->assertSame(0, WhatsAppConversationMessage::count());
        $this->assertSame(0, $business->whatsAppConversations()->count());
    }

    // --- Tenant isolation ---------------------------------------------------

    public function test_one_business_cannot_use_another_businesss_simulator_catalogue(): void
    {
        $a = $this->eligibleBusiness();
        $b = $this->eligibleBusiness();
        Product::factory()->create(['business_id' => $b->id, 'name' => 'Secret B Product', 'is_available' => true]);

        $response = $this->actingAs($a->owner)->followingRedirects()
            ->post('/ai-assistant/simulate', ['message' => 'What products do you have?']);

        $response->assertOk()->assertDontSee('Secret B Product');
    }
}
