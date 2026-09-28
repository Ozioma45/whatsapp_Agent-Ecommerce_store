<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\WhatsAppIntegrationSetting;
use App\Support\WhatsApp\OutgoingWhatsAppMessageService;
use App\Support\WhatsApp\WhatsAppSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOutgoingMessageTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OutgoingWhatsAppMessageService
    {
        return app(OutgoingWhatsAppMessageService::class);
    }

    // --- Successful send --------------------------------------------------

    public function test_it_sends_a_text_message_and_captures_the_returned_message_id(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT123']]], 200),
        ]);

        $integration = WhatsAppIntegrationSetting::factory()->create([
            'phone_number_id' => 'PNID-1',
            'access_token' => 'secret-access-token',
        ]);

        $result = $this->service()->send($integration->business, '15551234567', 'Hello from the store!');

        $this->assertTrue($result->successful);
        $this->assertSame(WhatsAppSendResult::ACCEPTED, $result->status);
        $this->assertSame('wamid.OUT123', $result->messageId);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v20.0/PNID-1/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-access-token')
                && $request['to'] === '15551234567'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello from the store!';
        });
    }

    public function test_it_uses_the_configured_api_version(): void
    {
        config(['services.whatsapp.api_version' => 'v99.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $integration = WhatsAppIntegrationSetting::factory()->create(['phone_number_id' => 'PNID-2']);

        $this->service()->send($integration->business, '15551234567', 'Hi');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v99.0/PNID-2/messages'));
    }

    public function test_it_strips_a_leading_plus_from_the_recipient(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $integration = WhatsAppIntegrationSetting::factory()->create();

        $this->service()->send($integration->business, '+15551234567', 'Hi');

        Http::assertSent(fn (Request $request) => $request['to'] === '15551234567');
    }

    // --- Missing / bad connection -------------------------------------

    public function test_it_refuses_to_send_when_the_business_has_no_integration(): void
    {
        Http::fake();
        $business = Business::factory()->create();

        $result = $this->service()->send($business, '15551234567', 'Hi');

        $this->assertFalse($result->successful);
        $this->assertSame(WhatsAppSendResult::NOT_CONNECTED, $result->status);
        Http::assertNothingSent();
    }

    public function test_it_refuses_to_send_when_the_integration_is_disconnected(): void
    {
        Http::fake();
        $integration = WhatsAppIntegrationSetting::factory()->disconnected()->create();

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertSame(WhatsAppSendResult::NOT_CONNECTED, $result->status);
        Http::assertNothingSent();
    }

    public function test_it_refuses_to_send_when_the_access_token_is_missing(): void
    {
        Http::fake();
        $integration = WhatsAppIntegrationSetting::factory()->create(['access_token' => null]);

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertSame(WhatsAppSendResult::NOT_CONNECTED, $result->status);
        Http::assertNothingSent();
    }

    // --- Validation -------------------------------------------------------

    public function test_it_rejects_an_invalid_recipient(): void
    {
        Http::fake();
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, 'not-a-number', 'Hi');

        $this->assertSame(WhatsAppSendResult::VALIDATION_FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_it_rejects_an_empty_message(): void
    {
        Http::fake();
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', '   ');

        $this->assertSame(WhatsAppSendResult::VALIDATION_FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_message_over_the_length_limit(): void
    {
        Http::fake();
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', str_repeat('a', 4097));

        $this->assertSame(WhatsAppSendResult::VALIDATION_FAILED, $result->status);
        Http::assertNothingSent();
    }

    // --- Meta failure responses --------------------------------------

    public function test_a_meta_authentication_failure_is_reported_safely(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401)]);
        $integration = WhatsAppIntegrationSetting::factory()->create(['access_token' => 'super-secret-token']);

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertFalse($result->successful);
        $this->assertSame(WhatsAppSendResult::AUTH_FAILURE, $result->status);
        $this->assertStringNotContainsString('super-secret-token', $result->error);
    }

    public function test_meta_rate_limiting_is_reported_as_such(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Too many requests']], 429)]);
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertSame(WhatsAppSendResult::RATE_LIMITED, $result->status);
    }

    public function test_an_invalid_request_to_meta_is_reported_as_invalid_recipient(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid recipient']], 400)]);
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertSame(WhatsAppSendResult::INVALID_RECIPIENT, $result->status);
    }

    public function test_an_unexpected_meta_response_is_reported_as_such(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertSame(WhatsAppSendResult::UNEXPECTED_ERROR, $result->status);
    }

    public function test_a_network_timeout_is_reported_safely(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        $integration = WhatsAppIntegrationSetting::factory()->create();

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertFalse($result->successful);
        $this->assertSame(WhatsAppSendResult::NETWORK_ERROR, $result->status);
    }

    // --- Tenant isolation -----------------------------------------------

    public function test_sending_for_one_business_never_uses_another_businesss_credentials(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $a = WhatsAppIntegrationSetting::factory()->create(['phone_number_id' => 'PNID-A', 'access_token' => 'token-a']);
        WhatsAppIntegrationSetting::factory()->create(['phone_number_id' => 'PNID-B', 'access_token' => 'token-b']);

        $this->service()->send($a->business, '15551234567', 'Hi');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'PNID-A')
                && $request->hasHeader('Authorization', 'Bearer token-a')
                && ! $request->hasHeader('Authorization', 'Bearer token-b');
        });
    }

    // --- No credential leakage -------------------------------------------

    public function test_the_result_object_never_carries_the_access_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $integration = WhatsAppIntegrationSetting::factory()->create(['access_token' => 'do-not-leak-me']);

        $result = $this->service()->send($integration->business, '15551234567', 'Hi');

        $this->assertStringNotContainsString('do-not-leak-me', json_encode($result));
    }
}
