<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\WhatsAppConversationMessage;
use App\Models\WhatsAppIntegrationSetting;
use App\Support\Ai\AiContext;
use App\Support\Ai\AiProviderInterface;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The end-to-end path: a verified webhook delivery reaching the
 * conversation engine and (when eligible) producing a real outgoing send.
 * Signature/verification behaviour itself is already covered in
 * WhatsAppWebhookTest and is not re-tested here beyond confirming it still
 * gates this new path too.
 */
class WhatsAppConversationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config(['services.whatsapp.app_secret' => self::SECRET]);
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

    private function fakeProvider(string $reply = 'Hello from the assistant'): void
    {
        $provider = new class($reply) implements AiProviderInterface
        {
            public function __construct(private readonly string $reply) {}

            public function generateResponse(AiContext $context, string $customerMessage): string
            {
                return $this->reply;
            }
        };

        $this->app->instance(AiProviderInterface::class, $provider);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload): TestResponse
    {
        $content = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $content, self::SECRET);

        return $this->withHeaders(['X-Hub-Signature-256' => $signature])->postJson('/webhooks/whatsapp', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function textMessagePayload(string $phoneNumberId, string $messageId, string $body = 'Hi'): array
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

    public function test_a_verified_incoming_message_produces_and_sends_an_ai_reply(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);
        $this->fakeProvider('Yes, we have that in stock.');
        $business = $this->readyBusiness();

        $this->postSignedWebhook($this->textMessagePayload($business->whatsAppIntegrationSetting->phone_number_id, 'wamid.IN1'))
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_conversation_messages', [
            'direction' => 'outbound',
            'content' => 'Yes, we have that in stock.',
            'whatsapp_message_id' => 'wamid.OUT1',
        ]);
    }

    public function test_an_invalid_signature_never_reaches_the_conversation_engine(): void
    {
        Http::fake();
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $payload = $this->textMessagePayload($business->whatsAppIntegrationSetting->phone_number_id, 'wamid.bad');
        $content = json_encode($payload);
        $badSignature = 'sha256='.hash_hmac('sha256', $content, 'wrong-secret');

        $this->withHeaders(['X-Hub-Signature-256' => $badSignature])->postJson('/webhooks/whatsapp', $payload)
            ->assertForbidden();

        $this->assertSame(0, WhatsAppConversationMessage::count());
        Http::assertNothingSent();
    }

    public function test_a_non_eligible_business_receives_no_ai_reply_via_the_webhook(): void
    {
        Http::fake();
        $this->fakeProvider();
        $business = Business::factory()->create(['plan_id' => Plan::where('slug', 'standard')->firstOrFail()->id]);
        $business->aiAssistantSettings()->update(['enabled' => true]);
        $integration = WhatsAppIntegrationSetting::factory()->create(['business_id' => $business->id]);

        $this->postSignedWebhook($this->textMessagePayload($integration->phone_number_id, 'wamid.std'))->assertOk();

        $this->assertSame(0, WhatsAppConversationMessage::count());
        Http::assertNothingSent();
    }

    public function test_a_duplicate_webhook_delivery_does_not_send_a_second_reply(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $payload = $this->textMessagePayload($business->whatsAppIntegrationSetting->phone_number_id, 'wamid.dup');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, WhatsAppConversationMessage::where('direction', 'outbound')->count());
    }

    public function test_an_unsupported_message_type_never_triggers_a_reply(): void
    {
        Http::fake();
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $payload = $this->textMessagePayload($business->whatsAppIntegrationSetting->phone_number_id, 'wamid.img');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'image';
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(0, WhatsAppConversationMessage::count());
        Http::assertNothingSent();
    }

    public function test_a_payload_supplied_business_id_is_ignored_for_conversation_routing(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $this->fakeProvider();
        $real = $this->readyBusiness();
        $other = Business::factory()->create();

        $payload = $this->textMessagePayload($real->whatsAppIntegrationSetting->phone_number_id, 'wamid.spoof');
        $payload['entry'][0]['changes'][0]['value']['business_id'] = $other->id;

        $this->postSignedWebhook($payload)->assertOk();

        $conversation = $real->whatsAppConversations()->firstOrFail();
        $this->assertSame(0, $other->whatsAppConversations()->count());
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_no_secrets_appear_in_the_webhook_response(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $this->fakeProvider();
        $business = $this->readyBusiness();
        $business->whatsAppIntegrationSetting->update(['access_token' => 'do-not-leak-outer-token']);

        $response = $this->postSignedWebhook(
            $this->textMessagePayload($business->whatsAppIntegrationSetting->fresh()->phone_number_id, 'wamid.leak')
        );

        $response->assertOk();
        $this->assertStringNotContainsString('do-not-leak-outer-token', $response->getContent());
    }
}
