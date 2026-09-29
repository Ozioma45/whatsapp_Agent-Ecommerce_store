<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\WhatsAppIntegrationSetting;
use App\Models\WhatsAppOrderDraft;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ConversationEngine;
use App\Support\Ai\ConversationOutcome;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Draft handling: adding, changing, removing, clearing, and revalidating a
 * WhatsApp order draft — all deterministic (OrderIntentParser), no AI
 * provider is ever consulted for any of this.
 */
class AiOrderDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        // The order flow never consults the AI provider, but a message
        // that isn't order-related still falls through to it — bind a
        // provider that fails loudly so a test would notice if that ever
        // happened unexpectedly for an order-flow message.
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                throw new \RuntimeException('The AI provider should not have been called for an order-flow message.');
            }
        });
    }

    private function readyBusiness(): Business
    {
        $business = Business::factory()->create([
            'plan_id' => Plan::where('slug', 'premium')->firstOrFail()->id,
        ]);
        $business->aiAssistantSettings()->update(['enabled' => true]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);

        return $business->fresh();
    }

    private function send(Business $business, string $text, ?string $id = null): string
    {
        return $this->outcome($business, $text, $id)->replyText;
    }

    private function outcome(Business $business, string $text, ?string $id = null): ConversationOutcome
    {
        $message = new IncomingWhatsAppMessage(
            messageId: $id ?? 'wamid.'.uniqid('', true),
            from: '15550001111',
            timestamp: now(),
            type: 'text',
            text: $text,
        );

        return app(ConversationEngine::class)->handleIncomingMessage($business, $message);
    }

    public function test_mentioning_a_product_creates_a_draft(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20, 'is_available' => true]);

        $reply = $this->send($business, 'I want the Blue Sneaker');

        $this->assertStringContainsString('Blue Sneaker', $reply);
        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertNotNull($draft);
        $this->assertSame(1, $draft->items()->count());
    }

    public function test_adding_multiple_products_across_messages(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Red Cap', 'price' => 5]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $reply = $this->send($business, 'Add the Red Cap too', id: 'wamid.2');

        $this->assertStringContainsString('Blue Sneaker', $reply);
        $this->assertStringContainsString('Red Cap', $reply);

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(2, $draft->items()->count());
    }

    public function test_a_quantity_in_the_message_sets_the_items_quantity(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, '3 Blue Sneaker', id: 'wamid.1');

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(3, $draft->items()->where('product_id', $product->id)->value('quantity'));

        $this->send($business, '5 Blue Sneaker', id: 'wamid.2');
        $this->assertSame(5, $draft->fresh()->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_removing_a_product_from_the_draft(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $reply = $this->send($business, 'Remove the Blue Sneaker', id: 'wamid.2');

        $this->assertStringContainsString('Removed', $reply);
        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(0, $draft->items()->count());
    }

    public function test_clearing_the_draft(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $reply = $this->send($business, 'clear', id: 'wamid.2');

        $this->assertStringContainsString('cleared', $reply);
        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(0, $draft->items()->count());
    }

    public function test_a_product_belonging_to_another_business_is_never_matched(): void
    {
        $business = $this->readyBusiness();
        $other = Business::factory()->create();
        Product::factory()->create(['business_id' => $other->id, 'name' => 'Rival Shoe', 'price' => 20]);

        // Not order-related from this business's point of view, since the
        // product doesn't belong to it — falls through to the AI provider,
        // which we've made throw, proving the order flow never matched it
        // (PROVIDER_UNAVAILABLE is ConversationEngine safely catching that
        // throw, not the order flow rejecting anything itself).
        $outcome = $this->outcome($business, 'I want the Rival Shoe');
        $this->assertSame(ConversationOutcome::PROVIDER_UNAVAILABLE, $outcome->status);
    }

    public function test_an_unavailable_product_is_never_matched(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Hidden Item', 'is_available' => false]);

        $outcome = $this->outcome($business, 'I want the Hidden Item');
        $this->assertSame(ConversationOutcome::PROVIDER_UNAVAILABLE, $outcome->status);
    }

    public function test_a_product_deleted_after_being_added_is_dropped_from_the_draft(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $product->delete();

        $reply = $this->send($business, 'checkout', id: 'wamid.2');

        $this->assertStringContainsString("haven't added anything available", $reply);
        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(0, $draft->items()->count());
        $this->assertNotSame(WhatsAppOrderDraft::PENDING_CONFIRMATION, $draft->pending_action);
    }

    public function test_a_changed_price_is_reflected_in_the_next_summary(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $product->update(['price' => 35]);

        // Any draft-changing message re-renders the summary from current
        // DB prices; mentioning the same product again (no name collected
        // yet) is enough to prove the price shown is always fresh.
        $reply = $this->send($business, 'Blue Sneaker', id: 'wamid.2');

        $this->assertStringContainsString('35.00', $reply);
        $this->assertStringNotContainsString('20.00', $reply);
    }
}
