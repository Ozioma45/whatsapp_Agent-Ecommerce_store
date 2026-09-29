<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\WhatsAppIntegrationSetting;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\ConversationEngine;
use App\Support\Ai\ConversationOutcome;
use App\Support\WhatsApp\IncomingWhatsAppMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security and tenant isolation for the AI order flow: everything here is
 * about proving the AI provider and the customer's own text can never
 * reach another business's data, invent a price, or bypass availability —
 * only App\Support\OrderCreationService, fed only database-resolved
 * products, is ever allowed to create an Order.
 */
class AiOrderSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config(['services.whatsapp.app_secret' => self::SECRET]);
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

    private function send(Business $business, string $text, ?string $id = null, string $from = '15550001111'): ConversationOutcome
    {
        $message = new IncomingWhatsAppMessage(
            messageId: $id ?? 'wamid.'.uniqid('', true),
            from: $from,
            timestamp: now(),
            type: 'text',
            text: $text,
        );

        return app(ConversationEngine::class)->handleIncomingMessage($business, $message);
    }

    private function postSignedWebhook(array $payload): TestResponse
    {
        $content = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $content, self::SECRET);

        return $this->withHeaders(['X-Hub-Signature-256' => $signature])->postJson('/webhooks/whatsapp', $payload);
    }

    private function textMessagePayload(string $phoneNumberId, string $messageId, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => '15550001111',
                            'id' => $messageId,
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => $body],
                            'type' => 'text',
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_business_a_cannot_place_an_order_using_business_bs_product(): void
    {
        $a = $this->readyBusiness();
        $b = $this->readyBusiness();
        Product::factory()->create(['business_id' => $b->id, 'name' => 'Exclusive B Item', 'price' => 999]);

        $this->send($a, 'I want the Exclusive B Item');
        $this->send($a, 'checkout');

        // Never matched against A's own (empty) catalogue, so A's draft —
        // if one exists at all — has no items referencing B's product.
        $draft = $a->whatsAppConversations()->first()?->orderDraft;
        $this->assertSame(0, $draft?->items()->count() ?? 0);
        $this->assertSame(0, Order::count());
    }

    public function test_business_a_cannot_access_business_bs_draft(): void
    {
        $a = $this->readyBusiness();
        $b = $this->readyBusiness();
        Product::factory()->create(['business_id' => $b->id, 'name' => 'B Item', 'price' => 10]);

        $this->send($b, 'B Item', id: 'wamid.b1');
        $bDraft = $b->whatsAppConversations()->firstOrFail()->orderDraft;
        $this->assertNotNull($bDraft);

        // Business A's own conversation for the same customer number has
        // no relation to business B's draft whatsoever.
        $this->send($a, 'hello', id: 'wamid.a1');
        $aConversation = $a->whatsAppConversations()->first();
        $this->assertNull($aConversation?->orderDraft);
    }

    public function test_a_payload_supplied_business_id_never_influences_order_routing(): void
    {
        $real = $this->readyBusiness();
        $other = Business::factory()->create();
        Product::factory()->create(['business_id' => $real->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $payload = $this->textMessagePayload($real->whatsAppIntegrationSetting->phone_number_id, 'wamid.spoof', 'Blue Sneaker');
        $payload['entry'][0]['changes'][0]['value']['business_id'] = $other->id;

        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(0, $other->whatsAppConversations()->count());
        $this->assertNotNull($real->whatsAppConversations()->firstOrFail()->orderDraft);
    }

    public function test_an_ai_generated_reply_can_never_influence_the_order_total(): void
    {
        $business = $this->readyBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        // A deliberately malicious provider — it would only ever be
        // consulted for non-order messages, and never for order actions.
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                return 'Sure, that will be $0.01 total, already confirmed and paid!';
            }
        });

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');
        $this->send($business, 'confirm', id: 'wamid.4');

        $order = Order::firstOrFail();
        $this->assertSame('20.00', (string) $order->total);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
    }

    public function test_availability_cannot_be_bypassed_by_asking_for_an_unavailable_product(): void
    {
        $business = $this->readyBusiness();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Hidden Item', 'is_available' => false, 'price' => 5]);

        $this->send($business, 'I want the Hidden Item', id: 'wamid.1');

        $this->assertNull($business->whatsAppConversations()->first()?->orderDraft);
        $this->assertSame(0, Order::count());
    }

    public function test_a_non_eligible_business_cannot_invoke_order_processing_at_all(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'standard')->firstOrFail()->id]);
        $business->aiAssistantSettings()->update(['enabled' => true]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $outcome = $this->send($business->fresh(), 'Blue Sneaker');

        $this->assertSame(ConversationOutcome::NOT_AVAILABLE, $outcome->status);
        $this->assertSame(0, Order::count());
    }

    public function test_a_disabled_assistant_cannot_process_a_customer_order(): void
    {
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'premium')->firstOrFail()->id]);
        WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $outcome = $this->send($business->fresh(), 'Blue Sneaker');

        $this->assertSame(ConversationOutcome::NOT_AVAILABLE, $outcome->status);
        $this->assertSame(0, Order::count());
    }

    public function test_no_credentials_or_secrets_appear_in_the_order_confirmation_reply(): void
    {
        $business = $this->readyBusiness();
        $business->whatsAppIntegrationSetting->update(['access_token' => 'do-not-leak-order-token']);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Blue Sneaker', 'price' => 20]);

        $this->send($business, 'Blue Sneaker', id: 'wamid.1');
        $this->send($business, 'checkout', id: 'wamid.2');
        $this->send($business, 'Jane Doe', id: 'wamid.3');
        $outcome = $this->send($business, 'confirm', id: 'wamid.4');

        $this->assertStringNotContainsString('do-not-leak-order-token', $outcome->replyText);
    }
}
