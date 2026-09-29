<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\WhatsAppIntegrationSetting;
use App\Models\WhatsAppOrderDraft;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ConversationEngine;
use App\Support\Ai\ConversationOutcome;
use App\Support\OrderCreationService;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The confirmation flow: presenting a summary, requiring an explicit
 * confirmation, collecting a customer name, re-confirming on drift, and
 * finally creating exactly one real Order through the same
 * OrderCreationService the storefront checkout uses.
 */
class AiOrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
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

    private function send(Business $business, string $text, ?string $id = null): ConversationOutcome
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

    public function test_a_draft_is_not_an_order_before_confirmation(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');

        $this->assertSame(0, Order::count());
    }

    public function test_explicit_confirmation_creates_exactly_one_pending_order(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want 2 Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3'); // provides name, shows summary
        $outcome = $this->send($business, 'confirm', id: 'wamid.4');

        $this->assertSame(1, Order::count());
        $order = Order::first();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame(Order::SOURCE_WHATSAPP_AI, $order->source);
        $this->assertSame('Jane Doe', $order->customer_name);
        $this->assertSame('15550001111', $order->customer_phone);
        $this->assertSame('40.00', (string) $order->total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($product->id, $order->items()->first()->product_id);
        $this->assertSame(2, $order->items()->first()->quantity);
        $this->assertStringContainsString($order->order_number, $outcome->replyText);
        $this->assertSame($order->id, $outcome->order->id);

        // The draft is gone once the order exists.
        $this->assertNull($business->whatsAppConversations()->firstOrFail()->orderDraft);
    }

    public function test_a_vague_acknowledgement_never_confirms_an_order(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                return 'Sure thing!';
            }
        });

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');
        $this->send($business, 'okay', id: 'wamid.4');
        $this->send($business, 'thanks', id: 'wamid.5');

        $this->assertSame(0, Order::count());

        $draft = $business->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertSame(WhatsAppOrderDraft::PENDING_CONFIRMATION, $draft->pending_action);
    }

    public function test_cancellation_prevents_order_creation(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');
        $outcome = $this->send($business, 'cancel', id: 'wamid.4');

        $this->assertStringContainsString('cancelled', $outcome->replyText);
        $this->assertSame(0, Order::count());
        $this->assertNull($business->whatsAppConversations()->firstOrFail()->orderDraft);
    }

    public function test_missing_customer_name_is_collected_before_confirmation_is_possible(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $checkoutOutcome = $this->send($business, 'checkout', id: 'wamid.2');

        $this->assertStringContainsString('name', strtolower($checkoutOutcome->replyText));

        // "confirm" is meaningless before a name has been given and the
        // summary has actually been shown, so it must not create an order.
        $confirmTooEarly = $this->send($business, 'confirm', id: 'wamid.3');
        $this->assertSame(0, Order::count());

        $this->send($business, 'Jane Doe', id: 'wamid.4');
        $this->send($business, 'confirm', id: 'wamid.5');

        $this->assertSame(1, Order::count());
        $this->assertSame('Jane Doe', Order::first()->customer_name);
    }

    public function test_a_price_change_requires_renewed_confirmation(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');

        $product->update(['price' => 35]);

        $outcome = $this->send($business, 'confirm', id: 'wamid.4');

        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('changed', $outcome->replyText);
        $this->assertStringContainsString('35.00', $outcome->replyText);

        // Confirming again now (against the refreshed snapshot) succeeds.
        $this->send($business, 'confirm', id: 'wamid.5');
        $this->assertSame(1, Order::count());
        $this->assertSame('35.00', (string) Order::first()->total);
    }

    public function test_a_duplicate_webhook_event_does_not_create_a_duplicate_order(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');

        $confirmMessage = new IncomingWhatsAppMessage('wamid.confirm', '15550001111', now(), 'text', 'confirm');
        $engine = app(ConversationEngine::class);

        $first = $engine->handleIncomingMessage($business, $confirmMessage);
        $second = $engine->handleIncomingMessage($business, $confirmMessage); // Meta's retry of the same delivery

        $this->assertSame(ConversationOutcome::ALREADY_PROCESSED, $second->status);
        $this->assertSame(1, Order::count());
    }

    public function test_a_failed_order_creation_leaves_no_partial_records(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->app->bind(OrderCreationService::class, function () {
            return new class extends OrderCreationService
            {
                public function create(Business $business, $items, string $customerName, string $customerPhone, string $source = Order::SOURCE_STOREFRONT): Order
                {
                    throw new RuntimeException('simulated database failure');
                }
            };
        });

        $this->send($business, 'I want the Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');
        $outcome = $this->send($business, 'confirm', id: 'wamid.4');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertStringContainsString('went wrong', strtolower($outcome->replyText));

        // The draft survives the failure so the customer can retry.
        $this->assertNotNull($business->whatsAppConversations()->firstOrFail()->orderDraft);
    }
}
