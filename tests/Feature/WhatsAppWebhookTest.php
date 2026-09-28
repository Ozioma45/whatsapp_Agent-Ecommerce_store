<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppIntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';

    private const VERIFY_TOKEN = 'test-verify-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => self::SECRET,
            'services.whatsapp.verify_token' => self::VERIFY_TOKEN,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $content = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $content, $secret ?? self::SECRET);

        return $this->withHeaders(['X-Hub-Signature-256' => $signature])
            ->postJson('/webhooks/whatsapp', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function textMessagePayload(string $phoneNumberId, string $messageId, string $body = 'Hello there', ?int $timestamp = null): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550009999',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'messages' => [[
                            'from' => '15550001111',
                            'id' => $messageId,
                            'timestamp' => (string) ($timestamp ?? now()->timestamp),
                            'text' => ['body' => $body],
                            'type' => 'text',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedMessagePayload(string $phoneNumberId, string $messageId): array
    {
        $payload = $this->textMessagePayload($phoneNumberId, $messageId);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'image';
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['image'] = ['id' => 'media-id'];

        return $payload;
    }

    // --- Verification -----------------------------------------------------

    public function test_webhook_verification_succeeds_with_the_correct_token(): void
    {
        $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => self::VERIFY_TOKEN,
            'hub_challenge' => 'challenge-123',
        ]))->assertOk()->assertSee('challenge-123');
    }

    public function test_webhook_verification_rejects_an_invalid_token(): void
    {
        $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge' => 'challenge-123',
        ]))->assertForbidden()->assertDontSee('challenge-123');
    }

    // --- Signature verification --------------------------------------------

    public function test_a_valid_signature_is_required_to_process_the_webhook(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $payload = $this->textMessagePayload($integration->phone_number_id, 'wamid.bad-signature');

        $this->postSignedWebhook($payload, 'wrong-secret')->assertForbidden();

        $this->assertDatabaseMissing('whatsapp_inbound_messages', ['whatsapp_message_id' => 'wamid.bad-signature']);
    }

    // --- Incoming messages --------------------------------------------------

    public function test_a_valid_text_message_is_recorded_for_the_right_business(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $payload = $this->textMessagePayload($integration->phone_number_id, 'wamid.abc123');

        $this->postSignedWebhook($payload)->assertOk()->assertSee('EVENT_RECEIVED');

        $this->assertDatabaseHas('whatsapp_inbound_messages', [
            'whatsapp_message_id' => 'wamid.abc123',
            'business_id' => $integration->business_id,
            'message_type' => 'text',
        ]);
    }

    public function test_an_unknown_phone_number_is_handled_safely(): void
    {
        $payload = $this->textMessagePayload('999999999999', 'wamid.unknown');

        $this->postSignedWebhook($payload)->assertOk()->assertSee('EVENT_RECEIVED');

        $this->assertDatabaseMissing('whatsapp_inbound_messages', ['whatsapp_message_id' => 'wamid.unknown']);
    }

    public function test_a_disconnected_integration_is_treated_like_an_unknown_number(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->disconnected()->create();
        $payload = $this->textMessagePayload($integration->phone_number_id, 'wamid.disconnected');

        $this->postSignedWebhook($payload)->assertOk();

        $this->assertDatabaseMissing('whatsapp_inbound_messages', ['whatsapp_message_id' => 'wamid.disconnected']);
    }

    public function test_a_malformed_payload_is_rejected(): void
    {
        $this->postSignedWebhook(['not' => 'a valid whatsapp payload'])->assertStatus(400);
    }

    public function test_an_unsupported_message_type_is_ignored_safely(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $payload = $this->unsupportedMessagePayload($integration->phone_number_id, 'wamid.image1');

        $this->postSignedWebhook($payload)->assertOk()->assertSee('EVENT_RECEIVED');

        $this->assertDatabaseMissing('whatsapp_inbound_messages', ['whatsapp_message_id' => 'wamid.image1']);
    }

    public function test_a_duplicate_message_delivery_is_only_processed_once(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create();
        $payload = $this->textMessagePayload($integration->phone_number_id, 'wamid.duplicate');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(1, WhatsAppInboundMessage::where('whatsapp_message_id', 'wamid.duplicate')->count());
    }

    // --- Tenant isolation -----------------------------------------------

    public function test_a_message_to_one_businesss_number_is_never_attributed_to_another(): void
    {
        $a = WhatsAppIntegrationSetting::factory()->create();
        $b = WhatsAppIntegrationSetting::factory()->create();

        $payload = $this->textMessagePayload($a->phone_number_id, 'wamid.tenant-a');
        $this->postSignedWebhook($payload)->assertOk();

        $message = WhatsAppInboundMessage::where('whatsapp_message_id', 'wamid.tenant-a')->firstOrFail();

        $this->assertSame($a->business_id, $message->business_id);
        $this->assertNotSame($b->business_id, $message->business_id);
    }

    public function test_a_supplied_business_id_in_the_payload_is_never_trusted(): void
    {
        $real = WhatsAppIntegrationSetting::factory()->create();
        $other = Business::factory()->create();

        $payload = $this->textMessagePayload($real->phone_number_id, 'wamid.spoofed');
        // A field no real WhatsApp payload contains — if the app ever read
        // something like this to identify the business, this would prove it.
        $payload['entry'][0]['changes'][0]['value']['business_id'] = $other->id;

        $this->postSignedWebhook($payload)->assertOk();

        $message = WhatsAppInboundMessage::where('whatsapp_message_id', 'wamid.spoofed')->firstOrFail();
        $this->assertSame($real->business_id, $message->business_id);
        $this->assertNotSame($other->id, $message->business_id);
    }

    // --- Credential protection -------------------------------------------

    public function test_access_tokens_and_verify_tokens_are_encrypted_at_rest(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create([
            'access_token' => 'plain-text-access-token',
            'webhook_verify_token' => 'plain-text-verify-token',
        ]);

        $raw = DB::table('whatsapp_integration_settings')->where('id', $integration->id)->first();

        $this->assertStringNotContainsString('plain-text-access-token', $raw->access_token);
        $this->assertStringNotContainsString('plain-text-verify-token', $raw->webhook_verify_token);
        $this->assertSame('plain-text-access-token', $integration->fresh()->access_token);
    }

    public function test_credentials_are_never_exposed_when_the_model_is_serialized(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create([
            'access_token' => 'super-secret-token',
        ]);

        $array = $integration->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('webhook_verify_token', $array);
        $this->assertStringNotContainsString('super-secret-token', $integration->toJson());
    }

    public function test_webhook_responses_never_contain_credentials(): void
    {
        $integration = WhatsAppIntegrationSetting::factory()->create(['access_token' => 'leak-check-token']);
        $payload = $this->textMessagePayload($integration->phone_number_id, 'wamid.no-leak');

        $response = $this->postSignedWebhook($payload);

        $response->assertOk();
        $this->assertStringNotContainsString('leak-check-token', $response->getContent());
    }
}
